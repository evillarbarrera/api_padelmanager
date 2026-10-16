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
require_once "../auth/auth_helper.php";
ob_end_clean();

// Disable exception throwing for mysqli
mysqli_report(MYSQLI_REPORT_OFF);

$user_id = (int)($_GET['user_id'] ?? 0);

if (!$user_id) {
    $tokenUserId = validateToken();
    if ($tokenUserId) {
        $user_id = (int)$tokenUserId;
    }
}

if (!$user_id) {
    echo json_encode([]);
    exit;
}

$result = [];

// 1. AMERICANO TOURNAMENTS
try {
    $sqlAm = "SELECT t.id, t.nombre, t.fecha, t.hora_inicio, t.hora_fin, t.estado, t.categoria, t.precio, t.imagen as poster_url,
                     COALESCE(c.nombre, 'Club de Pádel') as club_nombre, c.id as club_id,
                     tp.nombre_pareja, tp.id as inscripcion_id
              FROM torneo_participantes tp
              JOIN torneos_americanos t ON tp.torneo_id = t.id
              LEFT JOIN clubes c ON t.club_id = c.id
              WHERE (tp.jugador_id = $user_id OR tp.jugador2_id = $user_id)
              ORDER BY t.fecha DESC";

    $resAm = $conn->query($sqlAm);
    if ($resAm) {
        while ($row = $resAm->fetch_assoc()) {
            $row['tipo_torneo'] = 'americano';
            $row['table_source'] = 'americanos';
            $row['tipo'] = 'Americano';
            $row['partidos'] = [];

            // Get matches using tp.* to avoid unknown column errors
            $tid = (int)$row['id'];
            try {
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
                         WHERE tp.torneo_id = $tid
                         ORDER BY tp.ronda ASC, tp.id ASC";
                $resM = $conn->query($sqlM);
                if ($resM) {
                    while ($m = $resM->fetch_assoc()) {
                        // Normalize result fields
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

                        $m['es_mi_partido'] = (
                            ($m['jugador1_id'] ?? 0) == $user_id || 
                            ($m['jugador2_id'] ?? 0) == $user_id || 
                            ($m['jugador3_id'] ?? 0) == $user_id || 
                            ($m['jugador4_id'] ?? 0) == $user_id
                        );
                        $row['partidos'][] = $m;
                    }
                }
            } catch (Exception $e) {
                // Skip matches on error
            }
            $result[] = $row;
        }
    }
} catch (Exception $e) {
    // Skip americanos on error
}

// 2. V2 TOURNAMENTS
try {
    $check = $conn->query("SHOW TABLES LIKE 'torneo_inscripciones'");
    if ($check && $check->num_rows > 0) {
        $sqlV2 = "SELECT DISTINCT t.id, t.nombre, t.fecha_inicio as fecha, t.fecha_fin, t.estado, t.poster_url, t.precio,
                         COALESCE(c.nombre, 'Club de Pádel') as club_nombre, c.id as club_id,
                         tp.nombre_pareja, tp.id as pareja_id, tc.id as categoria_id, tc.nombre as categoria_nombre
                  FROM torneo_inscripciones ti
                  JOIN torneo_parejas tp ON ti.pareja_id = tp.id
                  JOIN torneo_categorias tc ON ti.categoria_id = tc.id
                  JOIN torneos_v2 t ON tc.torneo_id = t.id
                  LEFT JOIN clubes c ON t.club_id = c.id
                  WHERE (tp.jugador1_id = $user_id OR tp.jugador2_id = $user_id)
                  ORDER BY t.fecha_inicio DESC";

        $resV2 = $conn->query($sqlV2);
        if ($resV2) {
            while ($row = $resV2->fetch_assoc()) {
                $row['tipo_torneo'] = 'oficial';
                $row['table_source'] = 'v2';
                $row['tipo'] = 'Torneo';
                $row['partidos'] = [];

                $tid = (int)$row['id'];
                $pid = (int)$row['pareja_id'];

                try {
                    $sqlM = "SELECT p.*,
                                    p1.nombre_pareja as pareja1_nombre, 
                                    p2.nombre_pareja as pareja2_nombre,
                                    cat.nombre as categoria_nombre
                             FROM torneo_partidos_v2 p
                             LEFT JOIN torneo_parejas p1 ON p.pareja1_id = p1.id
                             LEFT JOIN torneo_parejas p2 ON p.pareja2_id = p2.id
                             LEFT JOIN torneo_categorias cat ON p.categoria_id = cat.id
                             WHERE cat.torneo_id = $tid
                             AND (p.pareja1_id = $pid OR p.pareja2_id = $pid)
                             ORDER BY p.id ASC";
                    $resM = $conn->query($sqlM);
                    if ($resM) {
                        while ($m = $resM->fetch_assoc()) {
                            $m['es_mi_partido'] = true;
                            $ganador = $m['ganador_id'] ?? null;
                            if ($ganador) {
                                $m['gane'] = ($ganador == $pid);
                                $m['resultado_t1'] = ($ganador == ($m['pareja1_id'] ?? 0)) ? 1 : 0;
                                $m['resultado_t2'] = ($ganador == ($m['pareja2_id'] ?? 0)) ? 1 : 0;
                            } else {
                                $m['resultado_t1'] = null;
                                $m['resultado_t2'] = null;
                            }
                            $row['partidos'][] = $m;
                        }
                    }
                } catch (Exception $e) {
                    // Skip matches on error
                }
                $result[] = $row;
            }
        }
    }
} catch (Exception $e) {
    // Skip V2 on error
}

// 3. LIGAS
try {
    $checkLiga = $conn->query("SHOW TABLES LIKE 'liga_parejas'");
    if ($checkLiga && $checkLiga->num_rows > 0) {
        $sqlLiga = "SELECT DISTINCT l.id, l.nombre, l.fecha_inicio as fecha, l.fecha_fin, l.estado, l.imagen_url as poster_url, l.precio,
                           COALESCE(c.nombre, 'Club de Pádel') as club_nombre, c.id as club_id,
                           lp.nombre_pareja, lp.id as pareja_id, lc.id as categoria_id, lc.nombre as categoria_nombre,
                           u1.nombre as j1_nombre, COALESCE(u1.apellido, '') as j1_apellido,
                           u2.nombre as j2_nombre, COALESCE(u2.apellido, '') as j2_apellido,
                           lp.nombre_externo1, lp.nombre_externo2
                    FROM liga_parejas lp
                    JOIN liga_categorias lc ON lp.categoria_id = lc.id
                    JOIN ligas l ON lc.liga_id = l.id
                    LEFT JOIN clubes c ON l.club_id = c.id
                    LEFT JOIN usuarios u1 ON lp.jugador1_id = u1.id
                    LEFT JOIN usuarios u2 ON lp.jugador2_id = u2.id
                    WHERE (lp.jugador1_id = $user_id OR lp.jugador2_id = $user_id)
                      AND (lp.estado IS NULL OR lp.estado != 'Cancelada')
                    ORDER BY l.fecha_inicio DESC";

        $resLiga = $conn->query($sqlLiga);
        if ($resLiga) {
            while ($row = $resLiga->fetch_assoc()) {
                $row['tipo_torneo'] = 'liga';
                $row['table_source'] = 'liga';
                $row['tipo'] = 'Liga';
                $row['partidos'] = [];

                $nom1 = trim(($row['j1_nombre'] ?? '') . ' ' . ($row['j1_apellido'] ?? ''));
                if (empty($nom1)) $nom1 = !empty($row['nombre_externo1']) ? $row['nombre_externo1'] : 'Jugador 1';
                $nom2 = trim(($row['j2_nombre'] ?? '') . ' ' . ($row['j2_apellido'] ?? ''));
                if (empty($nom2)) $nom2 = !empty($row['nombre_externo2']) ? $row['nombre_externo2'] : 'Jugador 2';

                if (empty($row['nombre_pareja']) || strpos($row['nombre_pareja'], ' / ') !== false || $row['nombre_pareja'] === 'Pareja' || $row['nombre_pareja'] === 'Jugador 1 / Jugador 2') {
                    $row['nombre_pareja'] = "$nom1 / $nom2";
                }

                $pid = (int)$row['pareja_id'];
                $catId = (int)$row['categoria_id'];

                try {
                    $sqlM = "SELECT p.*,
                                    p1.nombre_pareja as p1_raw_nombre, p1.nombre_externo1 as p1_ext1, p1.nombre_externo2 as p1_ext2,
                                    p2.nombre_pareja as p2_raw_nombre, p2.nombre_externo1 as p2_ext1, p2.nombre_externo2 as p2_ext2,
                                    u1.nombre as p1_j1_nom, COALESCE(u1.apellido, '') as p1_j1_ape,
                                    u2.nombre as p1_j2_nom, COALESCE(u2.apellido, '') as p1_j2_ape,
                                    u3.nombre as p2_j1_nom, COALESCE(u3.apellido, '') as p2_j1_ape,
                                    u4.nombre as p2_j2_nom, COALESCE(u4.apellido, '') as p2_j2_ape,
                                    cat.nombre as categoria_nombre,
                                    cn.nombre as cancha_nombre,
                                    j.nombre as jornada_nombre,
                                    g.nombre as grupo_nombre
                             FROM liga_partidos p
                             LEFT JOIN liga_parejas p1 ON p.pareja1_id = p1.id
                             LEFT JOIN liga_parejas p2 ON p.pareja2_id = p2.id
                             LEFT JOIN usuarios u1 ON p1.jugador1_id = u1.id
                             LEFT JOIN usuarios u2 ON p1.jugador2_id = u2.id
                             LEFT JOIN usuarios u3 ON p2.jugador1_id = u3.id
                             LEFT JOIN usuarios u4 ON p2.jugador2_id = u4.id
                             LEFT JOIN liga_categorias cat ON p1.categoria_id = cat.id
                             LEFT JOIN canchas cn ON p.cancha_id = cn.id
                             LEFT JOIN liga_jornadas j ON p.jornada_id = j.id
                             LEFT JOIN liga_grupos g ON p.grupo_id = g.id
                             WHERE (p.pareja1_id = $pid OR p.pareja2_id = $pid)
                             ORDER BY p.id ASC";
                    $resM = $conn->query($sqlM);
                    if ($resM) {
                        while ($m = $resM->fetch_assoc()) {
                            $m['es_mi_partido'] = true;

                            $n1 = trim(($m['p1_j1_nom'] ?? '') . ' ' . ($m['p1_j1_ape'] ?? ''));
                            if (empty($n1)) $n1 = !empty($m['p1_ext1']) ? $m['p1_ext1'] : 'Jugador 1';
                            $n2 = trim(($m['p1_j2_nom'] ?? '') . ' ' . ($m['p1_j2_ape'] ?? ''));
                            if (empty($n2)) $n2 = !empty($m['p1_ext2']) ? $m['p1_ext2'] : 'Jugador 2';
                            if (empty($m['p1_raw_nombre']) || strpos($m['p1_raw_nombre'], ' / ') !== false || $m['p1_raw_nombre'] === 'Pareja' || $m['p1_raw_nombre'] === 'Jugador 1 / Jugador 2' || empty($m['pareja1_nombre'])) {
                                $m['pareja1_nombre'] = $n1 . ' / ' . $n2;
                            } else {
                                $m['pareja1_nombre'] = $m['p1_raw_nombre'];
                            }
                            $m['p1_j1_nom'] = $n1;
                            $m['p1_j2_nom'] = $n2;

                            $n3 = trim(($m['p2_j1_nom'] ?? '') . ' ' . ($m['p2_j1_ape'] ?? ''));
                            if (empty($n3)) $n3 = !empty($m['p2_ext1']) ? $m['p2_ext1'] : 'Jugador 1';
                            $n4 = trim(($m['p2_j2_nom'] ?? '') . ' ' . ($m['p2_j2_ape'] ?? ''));
                            if (empty($n4)) $n4 = !empty($m['p2_ext2']) ? $m['p2_ext2'] : 'Jugador 2';
                            if (empty($m['p2_raw_nombre']) || strpos($m['p2_raw_nombre'], ' / ') !== false || $m['p2_raw_nombre'] === 'Pareja' || $m['p2_raw_nombre'] === 'Jugador 1 / Jugador 2' || empty($m['pareja2_nombre'])) {
                                $m['pareja2_nombre'] = $n3 . ' / ' . $n4;
                            } else {
                                $m['pareja2_nombre'] = $m['p2_raw_nombre'];
                            }
                            $m['p2_j1_nom'] = $n3;
                            $m['p2_j2_nom'] = $n4;

                            $ganador = $m['pareja_ganadora_id'] ?? null;
                            $estadoM = strtolower($m['estado'] ?? '');
                            if ($ganador && in_array($estadoM, ['jugado', 'walkover', 'finalizado'])) {
                                $m['gane'] = ($ganador == $pid);
                                $m['resultado_t1'] = ($ganador == ($m['pareja1_id'] ?? 0)) ? 1 : 0;
                                $m['resultado_t2'] = ($ganador == ($m['pareja2_id'] ?? 0)) ? 1 : 0;
                            } else {
                                $m['resultado_t1'] = null;
                                $m['resultado_t2'] = null;
                            }
                            $row['partidos'][] = $m;
                        }
                    }
                } catch (Exception $e) {
                    // Skip matches on error
                }
                $result[] = $row;
            }
        }
    }
} catch (Exception $e) {
    // Skip ligas on error
}

echo json_encode($result);
$conn->close();
?>
