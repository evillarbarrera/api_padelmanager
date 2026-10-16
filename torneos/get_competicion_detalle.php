<?php
ob_start();
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(200);
    exit;
}

ini_set('display_errors', 0);
error_reporting(0);

require_once "../db.php";
require_once "../ligas/posiciones_helper.php";
ob_end_clean();

mysqli_report(MYSQLI_REPORT_OFF);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$tipo = isset($_GET['tipo']) ? strtolower(trim($_GET['tipo'])) : '';

if (!$id || !$tipo) {
    echo json_encode(["success" => false, "error" => "Parámetros insuficientes (id y tipo requeridos)"]);
    exit;
}

$competicion = null;

try {
    // ---------------------------------------------------------
    // 1. AMERICANOS
    // ---------------------------------------------------------
    if ($tipo === 'americano' || $tipo === 'americanos') {
        $sqlA = "SELECT t.*, COALESCE(c.nombre, 'Club de Pádel') as club_nombre, c.direccion as club_direccion
                 FROM torneos_americanos t
                 LEFT JOIN clubes c ON t.club_id = c.id
                 WHERE t.id = $id";
        $resA = $conn->query($sqlA);
        if ($resA && $resA->num_rows > 0) {
            $rowA = $resA->fetch_assoc();
            
            // Participantes inscritos
            $sqlP = "SELECT tp.*, 
                            COALESCE(u1.nombre, tp.nombre_externo_1, 'Jugador 1') as jugador1_nombre, u1.foto as foto1,
                            COALESCE(u2.nombre, tp.nombre_externo_2, 'Jugador 2') as jugador2_nombre, u2.foto as foto2
                     FROM torneo_participantes tp
                     LEFT JOIN usuarios u1 ON tp.jugador_id = u1.id
                     LEFT JOIN usuarios u2 ON tp.jugador2_id = u2.id
                     WHERE tp.torneo_id = $id
                     ORDER BY tp.id ASC";
            $resP = $conn->query($sqlP);
            $inscritos = [];
            if ($resP) {
                while ($p = $resP->fetch_assoc()) {
                    if (empty($p['nombre_pareja'])) {
                        $p['nombre_pareja'] = $p['jugador1_nombre'] . ' / ' . $p['jugador2_nombre'];
                    }
                    $inscritos[] = [
                        'id' => $p['id'],
                        'nombre_pareja' => $p['nombre_pareja'],
                        'jugador1' => $p['jugador1_nombre'],
                        'jugador2' => $p['jugador2_nombre'],
                        'foto1' => $p['foto1'] ?? '',
                        'foto2' => $p['foto2'] ?? ''
                    ];
                }
            }

            // Partidos por rondas
            $sqlM = "SELECT tp.*,
                            COALESCE(u1.nombre, 'J1') as jugador1_nombre,
                            COALESCE(u2.nombre, 'J2') as jugador2_nombre,
                            COALESCE(u3.nombre, 'J3') as jugador3_nombre,
                            COALESCE(u4.nombre, 'J4') as jugador4_nombre
                     FROM torneo_partidos tp
                     LEFT JOIN usuarios u1 ON tp.jugador1_id = u1.id
                     LEFT JOIN usuarios u2 ON tp.jugador2_id = u2.id
                     LEFT JOIN usuarios u3 ON tp.jugador3_id = u3.id
                     LEFT JOIN usuarios u4 ON tp.jugador4_id = u4.id
                     WHERE tp.torneo_id = $id
                     ORDER BY tp.ronda ASC, tp.id ASC";
            $resM = $conn->query($sqlM);
            $partidos = [];
            $rondasMap = [];
            if ($resM) {
                while ($m = $resM->fetch_assoc()) {
                    $rondaNum = (int)($m['ronda'] ?? 1);
                    $isPlayed = (isset($m['finalizado']) && (int)$m['finalizado'] === 1) 
                             || (isset($m['estado']) && in_array(strtolower($m['estado']), ['jugado', 'finalizado', 'walkover']));

                    if ($isPlayed) {
                        $m['resultado_t1'] = $m['puntos_t1'] ?? $m['resultado_t1'] ?? 0;
                        $m['resultado_t2'] = $m['puntos_t2'] ?? $m['resultado_t2'] ?? 0;
                        $m['estado'] = $m['estado'] ?? 'Jugado';
                    } else {
                        $m['resultado_t1'] = null;
                        $m['resultado_t2'] = null;
                        $m['estado'] = $m['estado'] ?? 'Pendiente';
                    }

                    $m['pareja1_nombre'] = $m['jugador1_nombre'] . ' / ' . $m['jugador2_nombre'];
                    $m['pareja2_nombre'] = $m['jugador3_nombre'] . ' / ' . $m['jugador4_nombre'];
                    
                    if (!isset($rondasMap[$rondaNum])) {
                        $rondasMap[$rondaNum] = [
                            'id' => $rondaNum,
                            'numero_jornada' => $rondaNum,
                            'nombre' => "Ronda $rondaNum",
                            'partidos' => []
                        ];
                    }
                    $rondasMap[$rondaNum]['partidos'][] = $m;
                    $partidos[] = $m;
                }
            }
            ksort($rondasMap);
            $jornadas = array_values($rondasMap);

            // Calcular Posiciones del Americano
            $standingsMap = [];
            foreach ($inscritos as $ins) {
                $standingsMap[$ins['id']] = [
                    'posicion' => 0,
                    'nombre_pareja' => $ins['nombre_pareja'],
                    'pj' => 0, 'pg' => 0, 'pp' => 0, 'puntos' => 0,
                    'games_favor' => 0, 'games_contra' => 0, 'dif_games' => 0
                ];
            }

            foreach ($partidos as $m) {
                if ($m['resultado_t1'] !== null && $m['resultado_t2'] !== null) {
                    $pt1 = (int)$m['resultado_t1'];
                    $pt2 = (int)$m['resultado_t2'];
                    foreach ($inscritos as $ins) {
                        $pId = $ins['id'];
                        $isTeam1 = (strpos($ins['nombre_pareja'], $m['jugador1_nombre']) !== false || strpos($ins['nombre_pareja'], $m['jugador2_nombre']) !== false);
                        $isTeam2 = (strpos($ins['nombre_pareja'], $m['jugador3_nombre']) !== false || strpos($ins['nombre_pareja'], $m['jugador4_nombre']) !== false);
                        
                        if ($isTeam1 && isset($standingsMap[$pId])) {
                            $standingsMap[$pId]['pj']++;
                            $standingsMap[$pId]['games_favor'] += $pt1;
                            $standingsMap[$pId]['games_contra'] += $pt2;
                            $standingsMap[$pId]['dif_games'] += ($pt1 - $pt2);
                            if ($pt1 > $pt2) { $standingsMap[$pId]['pg']++; $standingsMap[$pId]['puntos'] += 3; }
                            elseif ($pt1 < $pt2) { $standingsMap[$pId]['pp']++; }
                            else { $standingsMap[$pId]['puntos'] += 1; }
                        } else if ($isTeam2 && isset($standingsMap[$pId])) {
                            $standingsMap[$pId]['pj']++;
                            $standingsMap[$pId]['games_favor'] += $pt2;
                            $standingsMap[$pId]['games_contra'] += $pt1;
                            $standingsMap[$pId]['dif_games'] += ($pt2 - $pt1);
                            if ($pt2 > $pt1) { $standingsMap[$pId]['pg']++; $standingsMap[$pId]['puntos'] += 3; }
                            elseif ($pt2 < $pt1) { $standingsMap[$pId]['pp']++; }
                            else { $standingsMap[$pId]['puntos'] += 1; }
                        }
                    }
                }
            }

            $tablaPosiciones = array_values($standingsMap);
            usort($tablaPosiciones, function($a, $b) {
                if ($b['puntos'] !== $a['puntos']) return $b['puntos'] - $a['puntos'];
                if ($b['dif_games'] !== $a['dif_games']) return $b['dif_games'] - $a['dif_games'];
                return $b['games_favor'] - $a['games_favor'];
            });
            foreach ($tablaPosiciones as $idx => &$pos) {
                $pos['posicion'] = $idx + 1;
            }
            unset($pos);

            $competicion = [
                'id' => (int)$rowA['id'],
                'tipo' => 'americano',
                'nombre' => $rowA['nombre'],
                'club_nombre' => $rowA['club_nombre'],
                'club_direccion' => $rowA['club_direccion'] ?? '',
                'precio' => (float)($rowA['precio'] ?? 0),
                'hora_inicio' => $rowA['hora_inicio'] ?? '',
                'fecha_display' => $rowA['fecha'],
                'estado' => $rowA['estado'] ?? 'Abierto',
                'categorias' => [
                    [
                        'id' => 0,
                        'nombre' => 'Categoría Única',
                        'inscritos' => $inscritos,
                        'jornadas' => $jornadas,
                        'tabla_posiciones' => $tablaPosiciones,
                        'posiciones' => $tablaPosiciones
                    ]
                ]
            ];
        }
    }

    // ---------------------------------------------------------
    // 2. TORNEOS V2 (OFICIALES)
    // ---------------------------------------------------------
    else if ($tipo === 'v2' || $tipo === 'torneo' || $tipo === 'oficial') {
        $sqlV2 = "SELECT t.*, COALESCE(c.nombre, 'Club de Pádel') as club_nombre, c.direccion as club_direccion
                  FROM torneos_v2 t
                  LEFT JOIN clubes c ON t.club_id = c.id
                  WHERE t.id = $id";
        $resV2 = $conn->query($sqlV2);
        if ($resV2 && $resV2->num_rows > 0) {
            $rowV2 = $resV2->fetch_assoc();
            
            $resCat = $conn->query("SELECT * FROM torneo_categorias WHERE torneo_id = $id ORDER BY id ASC");
            $categorias = [];

            if ($resCat) {
                while ($cat = $resCat->fetch_assoc()) {
                    $catId = (int)$cat['id'];

                    $sqlP = "SELECT ti.*, tp.nombre_pareja, tp.jugador1_id, tp.jugador2_id,
                                    COALESCE(u1.nombre, 'Jugador 1') as p1_nom, u1.foto as p1_foto,
                                    COALESCE(u2.nombre, 'Jugador 2') as p2_nom, u2.foto as p2_foto
                             FROM torneo_inscripciones ti
                             JOIN torneo_parejas tp ON ti.pareja_id = tp.id
                             LEFT JOIN usuarios u1 ON tp.jugador1_id = u1.id
                             LEFT JOIN usuarios u2 ON tp.jugador2_id = u2.id
                             WHERE ti.categoria_id = $catId ORDER BY ti.id ASC";
                    $resP = $conn->query($sqlP);
                    $inscritos = [];
                    if ($resP) {
                        while ($p = $resP->fetch_assoc()) {
                            $inscritos[] = [
                                'id' => $p['pareja_id'],
                                'pareja_id' => $p['pareja_id'],
                                'jugador1_id' => $p['jugador1_id'] ?? null,
                                'jugador2_id' => $p['jugador2_id'] ?? null,
                                'nombre_pareja' => $p['nombre_pareja'] ?? ($p['p1_nom'] . ' / ' . $p['p2_nom']),
                                'jugador1' => $p['p1_nom'],
                                'jugador2' => $p['p2_nom'],
                                'foto1' => $p['p1_foto'] ?? '',
                                'foto2' => $p['p2_foto'] ?? ''
                            ];
                        }
                    }

                    $sqlM = "SELECT p.*, 
                                    p1.nombre_pareja as pareja1_nombre, p2.nombre_pareja as pareja2_nombre,
                                    g.nombre as grupo_nombre
                             FROM torneo_partidos_v2 p 
                             JOIN torneo_parejas p1 ON p.pareja1_id = p1.id
                             JOIN torneo_parejas p2 ON p.pareja2_id = p2.id
                             LEFT JOIN torneo_grupos g ON p.grupo_id = g.id
                             WHERE p.categoria_id = $catId
                             ORDER BY p.id ASC";
                    $resM = $conn->query($sqlM);
                    $partidos = [];
                    $fasesMap = [];

                    if ($resM) {
                        while ($m = $resM->fetch_assoc()) {
                            $grupoNom = $m['grupo_nombre'] ?? 'Fase de Grupos';
                            if (!isset($fasesMap[$grupoNom])) {
                                $fasesMap[$grupoNom] = [
                                    'id' => count($fasesMap) + 1,
                                    'numero_jornada' => count($fasesMap) + 1,
                                    'nombre' => $grupoNom,
                                    'partidos' => []
                                ];
                            }
                            $m['resultado_t1'] = $m['set1_p1'] ?? $m['puntos_p1'] ?? null;
                            $m['resultado_t2'] = $m['set1_p2'] ?? $m['puntos_p2'] ?? null;
                            $fasesMap[$grupoNom]['partidos'][] = $m;
                            $partidos[] = $m;
                        }
                    }
                    $jornadas = array_values($fasesMap);

                    $standingsMap = [];
                    foreach ($inscritos as $ins) {
                        $standingsMap[$ins['id']] = [
                            'posicion' => 0,
                            'nombre_pareja' => $ins['nombre_pareja'],
                            'pj' => 0, 'pg' => 0, 'pp' => 0, 'puntos' => 0,
                            'sets_favor' => 0, 'sets_contra' => 0, 'dif_sets' => 0,
                            'games_favor' => 0, 'games_contra' => 0, 'dif_games' => 0
                        ];
                    }

                    foreach ($partidos as $m) {
                        $p1Id = (int)$m['pareja1_id'];
                        $p2Id = (int)$m['pareja2_id'];
                        $ganadorId = (int)($m['ganador_id'] ?? 0);

                        if ($ganadorId > 0 || ($m['resultado_t1'] !== null && $m['resultado_t2'] !== null)) {
                            if (isset($standingsMap[$p1Id])) {
                                $standingsMap[$p1Id]['pj']++;
                                if ($ganadorId === $p1Id) {
                                    $standingsMap[$p1Id]['pg']++;
                                    $standingsMap[$p1Id]['puntos'] += 3;
                                } else if ($ganadorId === $p2Id) {
                                    $standingsMap[$p1Id]['pp']++;
                                }
                            }
                            if (isset($standingsMap[$p2Id])) {
                                $standingsMap[$p2Id]['pj']++;
                                if ($ganadorId === $p2Id) {
                                    $standingsMap[$p2Id]['pg']++;
                                    $standingsMap[$p2Id]['puntos'] += 3;
                                } else if ($ganadorId === $p1Id) {
                                    $standingsMap[$p2Id]['pp']++;
                                }
                            }
                        }
                    }

                    $tablaPosiciones = array_values($standingsMap);
                    usort($tablaPosiciones, function($a, $b) {
                        if ($b['puntos'] !== $a['puntos']) return $b['puntos'] - $a['puntos'];
                        return $b['pg'] - $a['pg'];
                    });
                    foreach ($tablaPosiciones as $idx => &$pos) {
                        $pos['posicion'] = $idx + 1;
                    }
                    unset($pos);

                    $categorias[] = [
                        'id' => $catId,
                        'nombre' => $cat['nombre'],
                        'inscritos' => $inscritos,
                        'jornadas' => $jornadas,
                        'tabla_posiciones' => $tablaPosiciones,
                        'posiciones' => $tablaPosiciones
                    ];
                }
            }

            $competicion = [
                'id' => (int)$rowV2['id'],
                'tipo' => 'v2',
                'nombre' => $rowV2['nombre'],
                'club_nombre' => $rowV2['club_nombre'],
                'club_direccion' => $rowV2['club_direccion'] ?? '',
                'precio' => (float)($rowV2['precio'] ?? 0),
                'poster_url' => $rowV2['poster_url'] ?? '',
                'bases_url' => $rowV2['bases_url'] ?? '',
                'fecha_display' => $rowV2['fecha_inicio'] . ' al ' . $rowV2['fecha_fin'],
                'estado' => $rowV2['estado'] ?? 'Abierto',
                'categorias' => $categorias
            ];
        }
    }

    // ---------------------------------------------------------
    // 3. LIGAS DE PÁDEL
    // ---------------------------------------------------------
    else if ($tipo === 'liga' || $tipo === 'ligas') {
        $sqlL = "SELECT l.*, COALESCE(c.nombre, 'Club de Pádel') as club_nombre, c.direccion as club_direccion
                 FROM ligas l
                 LEFT JOIN clubes c ON l.club_id = c.id
                 WHERE l.id = $id";
        $resL = $conn->query($sqlL);
        if ($resL && $resL->num_rows > 0) {
            $rowL = $resL->fetch_assoc();

            $resCat = $conn->query("SELECT * FROM liga_categorias WHERE liga_id = $id ORDER BY id ASC");
            $categorias = [];

            if ($resCat) {
                while ($cat = $resCat->fetch_assoc()) {
                    $catId = (int)$cat['id'];

                    $sqlP = "SELECT p.*, 
                                    u1.nombre as p1_nom, COALESCE(u1.apellido, '') as p1_ape, u1.foto as p1_foto,
                                    u2.nombre as p2_nom, COALESCE(u2.apellido, '') as p2_ape, u2.foto as p2_foto
                             FROM liga_parejas p
                             LEFT JOIN usuarios u1 ON p.jugador1_id = u1.id
                             LEFT JOIN usuarios u2 ON p.jugador2_id = u2.id
                             WHERE p.categoria_id = $catId ORDER BY p.id DESC";
                    $resP = $conn->query($sqlP);
                    $inscritos = [];
                    if ($resP) {
                        while ($par = $resP->fetch_assoc()) {
                            $nom1 = trim(($par['p1_nom'] ?? '') . ' ' . ($par['p1_ape'] ?? ''));
                            if (empty($nom1)) $nom1 = !empty($par['nombre_externo1']) ? $par['nombre_externo1'] : 'Jugador 1';
                            $nom2 = trim(($par['p2_nom'] ?? '') . ' ' . ($par['p2_ape'] ?? ''));
                            if (empty($nom2)) $nom2 = !empty($par['nombre_externo2']) ? $par['nombre_externo2'] : 'Jugador 2';

                            $nomPareja = $par['nombre_pareja'];
                            if (empty($nomPareja)) $nomPareja = $nom1 . ' / ' . $nom2;

                            $inscritos[] = [
                                'id' => $par['id'],
                                'nombre_pareja' => $nomPareja,
                                'jugador1' => $nom1,
                                'jugador2' => $nom2,
                                'jugador1_id' => $par['jugador1_id'] ?? null,
                                'jugador2_id' => $par['jugador2_id'] ?? null,
                                'foto1' => $par['p1_foto'] ?? '',
                                'foto2' => $par['p2_foto'] ?? ''
                            ];
                        }
                    }

                    $resJ = $conn->query("SELECT * FROM liga_jornadas WHERE categoria_id = $catId ORDER BY numero_jornada ASC");
                    $jornadas = [];

                    if ($resJ) {
                        while ($j = $resJ->fetch_assoc()) {
                            $jId = (int)$j['id'];
                            $sqlPartidos = "SELECT p.*, cn.nombre as cancha_nombre,
                                                   par1.nombre_pareja as p1_raw_nombre, par1.nombre_externo1 as p1_ext1, par1.nombre_externo2 as p1_ext2,
                                                   par2.nombre_pareja as p2_raw_nombre, par2.nombre_externo1 as p2_ext1, par2.nombre_externo2 as p2_ext2,
                                                   par1.jugador1_id as p1_j1_id, par1.jugador2_id as p1_j2_id,
                                                   par2.jugador1_id as p2_j1_id, par2.jugador2_id as p2_j2_id,
                                                   u1.nombre as p1_u1_nom, COALESCE(u1.apellido, '') as p1_u1_ape,
                                                   u2.nombre as p1_u2_nom, COALESCE(u2.apellido, '') as p1_u2_ape,
                                                   u3.nombre as p2_u1_nom, COALESCE(u3.apellido, '') as p2_u1_ape,
                                                   u4.nombre as p2_u2_nom, COALESCE(u4.apellido, '') as p2_u2_ape
                                            FROM liga_partidos p
                                            LEFT JOIN canchas cn ON p.cancha_id = cn.id
                                            LEFT JOIN liga_parejas par1 ON p.pareja1_id = par1.id
                                            LEFT JOIN usuarios u1 ON par1.jugador1_id = u1.id
                                            LEFT JOIN usuarios u2 ON par1.jugador2_id = u2.id
                                            LEFT JOIN liga_parejas par2 ON p.pareja2_id = par2.id
                                            LEFT JOIN usuarios u3 ON par2.jugador1_id = u3.id
                                            LEFT JOIN usuarios u4 ON par2.jugador2_id = u4.id
                                            WHERE p.jornada_id = $jId ORDER BY p.id ASC";
                            $resPart = $conn->query($sqlPartidos);
                            $partidos = [];
                            if ($resPart) {
                                while ($pt = $resPart->fetch_assoc()) {
                                    $n1 = trim(($pt['p1_u1_nom'] ?? '') . ' ' . ($pt['p1_u1_ape'] ?? ''));
                                    if (empty($n1)) $n1 = !empty($pt['p1_ext1']) ? $pt['p1_ext1'] : 'Jugador 1';
                                    $n2 = trim(($pt['p1_u2_nom'] ?? '') . ' ' . ($pt['p1_u2_ape'] ?? ''));
                                    if (empty($n2)) $n2 = !empty($pt['p1_ext2']) ? $pt['p1_ext2'] : 'Jugador 2';
                                    if (empty($pt['p1_raw_nombre']) || strpos($pt['p1_raw_nombre'], ' / ') !== false || $pt['p1_raw_nombre'] === 'Pareja' || $pt['p1_raw_nombre'] === 'Jugador 1 / Jugador 2') {
                                        $pt['pareja1_nombre'] = $n1 . ' / ' . $n2;
                                    } else {
                                        $pt['pareja1_nombre'] = $pt['p1_raw_nombre'];
                                    }
                                    $pt['p1_j1_nom'] = $n1;
                                    $pt['p1_j2_nom'] = $n2;

                                    $n3 = trim(($pt['p2_u1_nom'] ?? '') . ' ' . ($pt['p2_u1_ape'] ?? ''));
                                    if (empty($n3)) $n3 = !empty($pt['p2_ext1']) ? $pt['p2_ext1'] : 'Jugador 1';
                                    $n4 = trim(($pt['p2_u2_nom'] ?? '') . ' ' . ($pt['p2_u2_ape'] ?? ''));
                                    if (empty($n4)) $n4 = !empty($pt['p2_ext2']) ? $pt['p2_ext2'] : 'Jugador 2';
                                    if (empty($pt['p2_raw_nombre']) || strpos($pt['p2_raw_nombre'], ' / ') !== false || $pt['p2_raw_nombre'] === 'Pareja' || $pt['p2_raw_nombre'] === 'Jugador 1 / Jugador 2') {
                                        $pt['pareja2_nombre'] = $n3 . ' / ' . $n4;
                                    } else {
                                        $pt['pareja2_nombre'] = $pt['p2_raw_nombre'];
                                    }
                                    $pt['p2_j1_nom'] = $n3;
                                    $pt['p2_j2_nom'] = $n4;

                                    $partidos[] = $pt;
                                }
                            }
                            $j['partidos'] = $partidos;
                            $jornadas[] = $j;
                        }
                    }

                    $posList = obtenerPosicionesArray($conn, $catId);
                    $tablaPosiciones = [];
                    $rank = 1;
                    foreach ($posList as $posRow) {
                        $posRow['posicion'] = $rank++;
                        $n1 = trim(($posRow['p1_nombre'] ?? '') . ' ' . ($posRow['p1_apellido'] ?? ''));
                        if (empty($n1)) $n1 = !empty($posRow['nombre_externo1']) ? $posRow['nombre_externo1'] : 'Jugador 1';
                        $n2 = trim(($posRow['p2_nombre'] ?? '') . ' ' . ($posRow['p2_apellido'] ?? ''));
                        if (empty($n2)) $n2 = !empty($posRow['nombre_externo2']) ? $posRow['nombre_externo2'] : 'Jugador 2';
                        
                        $posRow['jugador1'] = $n1;
                        $posRow['jugador2'] = $n2;
                        $posRow['p1_nombre'] = $n1;
                        $posRow['p2_nombre'] = $n2;
                        $posRow['p1_foto'] = $posRow['p1_foto'] ?? '';
                        $posRow['p2_foto'] = $posRow['p2_foto'] ?? '';
                        $tablaPosiciones[] = $posRow;
                    }

                    $categorias[] = [
                        'id' => $catId,
                        'nombre' => $cat['nombre'],
                        'inscritos' => $inscritos,
                        'jornadas' => $jornadas,
                        'tabla_posiciones' => $tablaPosiciones,
                        'posiciones' => $tablaPosiciones
                    ];
                }
            }

            $competicion = [
                'id' => (int)$rowL['id'],
                'tipo' => 'liga',
                'nombre' => $rowL['nombre'],
                'club_nombre' => $rowL['club_nombre'],
                'club_direccion' => $rowL['club_direccion'] ?? '',
                'precio' => (float)($rowL['precio'] ?? 0),
                'imagen_url' => $rowL['imagen_url'] ?? '',
                'fecha_display' => $rowL['fecha_inicio'] . ' al ' . $rowL['fecha_fin'],
                'estado' => $rowL['estado'] ?? 'Inscripción',
                'categorias' => $categorias
            ];
        }
    }

    if ($competicion) {
        echo json_encode(["success" => true, "competicion" => $competicion]);
    } else {
        echo json_encode(["success" => false, "error" => "Competición no encontrada"]);
    }
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}

$conn->close();
?>
