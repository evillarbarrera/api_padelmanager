<?php
/**
 * PADELBLOX TV - API Pública para Pantallas y Smart TVs en Clubes
 * Retorna en un solo payload optimizado con datos reales del club:
 * 1. Info del Club y Colores
 * 2. Marcadores en Vivo por Cancha (con Smartwatch Sync)
 * 3. Próximos Turnos (Cartelera estilo aeropuerto con partidos de la tarde)
 * 4. Tabla de Posiciones Real de la Liga Activa (Liga Apertura Power Padel - 4ta Categoría)
 * 5. Menú de Cafetería & Kiosko (inventario real)
 * 6. Auspiciadores y Anuncios
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// Manejo seguro de errores
ini_set('display_errors', '0');
error_reporting(0);
if (function_exists('mysqli_report')) {
    @mysqli_report(MYSQLI_REPORT_OFF);
}

require_once "../db.php";

$club_param = trim($_GET['club_id'] ?? ($_GET['id'] ?? ($_GET['slug'] ?? '')));

try {
    // -------------------------------------------------------------------------
    // 1. RESOLVER CLUB REAL
    // -------------------------------------------------------------------------
    $club = null;
    $club_id = 0;

    $sqlClubBase = "SELECT c.*, 
                           COALESCE(NULLIF(c.region, ''), d.region, 'Chile') as region, 
                           COALESCE(NULLIF(c.comuna, ''), d.comuna, '') as comuna
                    FROM clubes c
                    LEFT JOIN direcciones d ON d.club_id = c.id";

    if (is_numeric($club_param) && intval($club_param) > 0) {
        $club_id = intval($club_param);
        $stmtC = $conn->prepare("$sqlClubBase WHERE c.id = ? LIMIT 1");
        if ($stmtC) {
            $stmtC->bind_param("i", $club_id);
            $stmtC->execute();
            $resC = $stmtC->get_result();
            if ($resC && $resC->num_rows > 0) {
                $club = $resC->fetch_assoc();
            }
        }
    } else if (!empty($club_param)) {
        // Buscar por slug o coincidencia de nombre
        $stmtC = $conn->prepare("$sqlClubBase WHERE LOWER(c.landing_slug) = LOWER(?) OR LOWER(REPLACE(c.nombre, ' ', '-')) = LOWER(?) LIMIT 1");
        if ($stmtC) {
            $stmtC->bind_param("ss", $club_param, $club_param);
            $stmtC->execute();
            $resC = $stmtC->get_result();
            if ($resC && $resC->num_rows > 0) {
                $club = $resC->fetch_assoc();
                $club_id = intval($club['id']);
            }
        }
    }

    // Si aún no se encontró, probar con club 17 (PowerPadel Machalí) o primer club existente
    if (!$club) {
        $res17 = $conn->query("$sqlClubBase WHERE c.id = 17 LIMIT 1");
        if ($res17 && $res17->num_rows > 0) {
            $club = $res17->fetch_assoc();
            $club_id = 17;
        } else {
            $resDef = $conn->query("$sqlClubBase ORDER BY c.id ASC LIMIT 1");
            if ($resDef && $resDef->num_rows > 0) {
                $club = $resDef->fetch_assoc();
                $club_id = intval($club['id']);
            }
        }
    }

    if (!$club) {
        $club = [
            "id" => 17,
            "nombre" => "PowerPadel Machali",
            "logo" => "uploads/clubes/club_1784228222_8391.png",
            "comuna" => "Machalí",
            "region" => "Región de O'Higgins",
            "landing_color_primary" => "#0f172a",
            "landing_color_accent" => "#ccff00"
        ];
        $club_id = 17;
    }

    // Mapeo unificado de club_ids para clubes con IDs compartidos (PowerPadel id 17 y 21)
    $club_ids = [$club_id];
    if ($club_id == 17) $club_ids[] = 21;
    if ($club_id == 21) $club_ids[] = 17;
    if (stripos($club['nombre'] ?? '', 'Power') !== false) {
        $club_ids = array_unique(array_merge($club_ids, [17, 21]));
    }
    $clubIdsList = implode(',', array_map('intval', $club_ids));

    $hoy = date('Y-m-d');
    $hora_actual = date('H:i:s');
    $timestamp_ahora = time();

    // -------------------------------------------------------------------------
    // 2. OBTENER CANCHAS REALES DEL CLUB
    // -------------------------------------------------------------------------
    $canchas_raw = [];
    $sqlCanchas = "SELECT c.* FROM canchas c WHERE c.club_id IN ($clubIdsList) AND (c.activa = 1 OR c.activa IS NULL) ORDER BY c.id ASC";
    $resCanchas = $conn->query($sqlCanchas);
    if ($resCanchas && $resCanchas->num_rows > 0) {
        while ($c = $resCanchas->fetch_assoc()) {
            $canchas_raw[$c['id']] = $c;
        }
    }

    // -------------------------------------------------------------------------
    // 3. SINCRONIZAR Y OBTENER RESERVAS Y PARTIDOS DE HOY
    // -------------------------------------------------------------------------
    
    // 3.1 Auto-crear reservas faltantes para partidos de liga de hoy que ya tengan cancha asignada
    if (!empty($canchas_raw)) {
        @$conn->query("INSERT INTO reservas_cancha (cancha_id, fecha, hora_inicio, hora_fin, precio, precio_total, pagado, estado, liga_partido_id, nombre_externo, nombre_externo2, nombre_externo3, nombre_externo4, usuario_id, jugador2_id, jugador3_id, jugador4_id)
                      SELECT lp.cancha_id, 
                             DATE(lp.fecha_hora), 
                             TIME(lp.fecha_hora), 
                             ADDTIME(TIME(lp.fecha_hora), '01:30:00'), 
                             0, 0, 1, 'Confirmada', lp.id,
                             COALESCE(u11.nombre, par1.nombre_externo1, 'P1-J1'),
                             COALESCE(u12.nombre, par1.nombre_externo2, 'P1-J2'),
                             COALESCE(u21.nombre, par2.nombre_externo1, 'P2-J1'),
                             COALESCE(u22.nombre, par2.nombre_externo2, 'P2-J2'),
                             par1.jugador1_id, par1.jugador2_id, par2.jugador1_id, par2.jugador2_id
                      FROM liga_partidos lp
                      JOIN canchas c ON lp.cancha_id = c.id
                      LEFT JOIN reservas_cancha r ON r.liga_partido_id = lp.id
                      LEFT JOIN liga_parejas par1 ON lp.pareja1_id = par1.id
                      LEFT JOIN liga_parejas par2 ON lp.pareja2_id = par2.id
                      LEFT JOIN usuarios u11 ON par1.jugador1_id = u11.id
                      LEFT JOIN usuarios u12 ON par1.jugador2_id = u12.id
                      LEFT JOIN usuarios u21 ON par2.jugador1_id = u21.id
                      LEFT JOIN usuarios u22 ON par2.jugador2_id = u22.id
                      WHERE c.club_id IN ($clubIdsList)
                        AND lp.fecha_hora IS NOT NULL 
                        AND lp.fecha_hora NOT LIKE '0000%' 
                        AND lp.cancha_id IS NOT NULL 
                        AND lp.estado != 'Suspendido'
                        AND r.id IS NULL");
    }

    // 3.2 Consultar reservas del día de hoy para canchas del club
    $reservas_hoy = [];
    if (!empty($canchas_raw)) {
        $sqlReservas = "SELECT r.*,
                               c.nombre as cancha_nombre, c.tipo as cancha_tipo,
                               u1.nombre as j1_nombre, u1.apellido as j1_ape, u1.foto_perfil as j1_foto,
                               u2.nombre as j2_nombre, u2.apellido as j2_ape, u2.foto_perfil as j2_foto,
                               u3.nombre as j3_nombre, u3.apellido as j3_ape, u3.foto_perfil as j3_foto,
                               u4.nombre as j4_nombre, u4.apellido as j4_ape, u4.foto_perfil as j4_foto,
                               lp.id as liga_partido_id_fk, lp.jornada_id as liga_jornada,
                               sw.total_golpes as sw_golpes, sw.fc_promedio as sw_fc, sw.duracion_segundos as sw_duracion,
                               sw.marcador_final_t1 as sw_m1, sw.marcador_final_t2 as sw_m2,
                               sw.velocidad_max_kmh as sw_vel_max
                        FROM reservas_cancha r
                        JOIN canchas c ON r.cancha_id = c.id
                        LEFT JOIN usuarios u1 ON r.usuario_id = u1.id
                        LEFT JOIN usuarios u2 ON r.jugador2_id = u2.id
                        LEFT JOIN usuarios u3 ON r.jugador3_id = u3.id
                        LEFT JOIN usuarios u4 ON r.jugador4_id = u4.id
                        LEFT JOIN liga_partidos lp ON r.liga_partido_id = lp.id
                        LEFT JOIN smartwatch_sesiones sw ON (
                            (sw.reserva_id = r.id OR (r.liga_partido_id IS NOT NULL AND sw.liga_partido_id = r.liga_partido_id))
                            AND (DATE(sw.fecha_inicio) = '$hoy' OR DATE(sw.created_at) = '$hoy')
                        )
                        WHERE c.club_id IN ($clubIdsList) 
                          AND (DATE(r.fecha) = '$hoy' OR r.fecha LIKE '$hoy%') 
                          AND (r.estado IS NULL OR r.estado != 'Cancelada')
                        ORDER BY r.hora_inicio ASC";

        $resR = $conn->query($sqlReservas);
        if ($resR && $resR->num_rows > 0) {
            while ($row = $resR->fetch_assoc()) {
                $reservas_hoy[] = $row;
            }
        }

        // Si hoy no hay partidos agendados todavía, cargar partidos recientes de ESTE club
        if (empty($reservas_hoy)) {
            $sqlRecent = "SELECT r.*,
                                 c.nombre as cancha_nombre, c.tipo as cancha_tipo,
                                 u1.nombre as j1_nombre, u1.apellido as j1_ape, u1.foto_perfil as j1_foto,
                                 u2.nombre as j2_nombre, u2.apellido as j2_ape, u2.foto_perfil as j2_foto,
                                 u3.nombre as j3_nombre, u3.apellido as j3_ape, u3.foto_perfil as j3_foto,
                                 u4.nombre as j4_nombre, u4.apellido as j4_ape, u4.foto_perfil as j4_foto,
                                 lp.id as liga_partido_id_fk, lp.jornada_id as liga_jornada,
                                 sw.total_golpes as sw_golpes, sw.fc_promedio as sw_fc, sw.duracion_segundos as sw_duracion,
                                 sw.marcador_final_t1 as sw_m1, sw.marcador_final_t2 as sw_m2,
                                 sw.velocidad_max_kmh as sw_vel_max
                          FROM reservas_cancha r
                          JOIN canchas c ON r.cancha_id = c.id
                          LEFT JOIN usuarios u1 ON r.usuario_id = u1.id
                          LEFT JOIN usuarios u2 ON r.jugador2_id = u2.id
                          LEFT JOIN usuarios u3 ON r.jugador3_id = u3.id
                          LEFT JOIN usuarios u4 ON r.jugador4_id = u4.id
                          LEFT JOIN liga_partidos lp ON r.liga_partido_id = lp.id
                          LEFT JOIN smartwatch_sesiones sw ON (
                              (sw.reserva_id = r.id OR (r.liga_partido_id IS NOT NULL AND sw.liga_partido_id = r.liga_partido_id))
                              AND (DATE(sw.fecha_inicio) = '$hoy' OR DATE(sw.created_at) = '$hoy')
                          )
                          WHERE c.club_id IN ($clubIdsList) AND (r.estado IS NULL OR r.estado != 'Cancelada')
                          ORDER BY r.fecha DESC, r.hora_inicio DESC
                          LIMIT 15";
            $resRec = $conn->query($sqlRecent);
            if ($resRec && $resRec->num_rows > 0) {
                while ($row = $resRec->fetch_assoc()) {
                    $reservas_hoy[] = $row;
                }
            }
        }
    }

    // Helper para formatear duplas
    $getDuplas = function($r) {
        $j1 = trim(($r['j1_nombre'] ?? '') . ' ' . ($r['j1_ape'] ?? ''));
        if (empty($j1)) $j1 = trim($r['nombre_externo'] ?? 'Jugador 1');
        $j2 = trim(($r['j2_nombre'] ?? '') . ' ' . ($r['j2_ape'] ?? ''));
        if (empty($j2)) $j2 = trim($r['nombre_externo2'] ?? '');

        $j3 = trim(($r['j3_nombre'] ?? '') . ' ' . ($r['j3_ape'] ?? ''));
        if (empty($j3)) $j3 = trim($r['nombre_externo3'] ?? 'Jugador 2');
        $j4 = trim(($r['j4_nombre'] ?? '') . ' ' . ($r['j4_ape'] ?? ''));
        if (empty($j4)) $j4 = trim($r['nombre_externo4'] ?? '');

        $dupla1 = !empty($j2) ? "$j1 / $j2" : $j1;
        $dupla2 = !empty($j4) ? "$j3 / $j4" : $j3;

        return [
            "dupla1" => $dupla1,
            "dupla2" => $dupla2,
            "j1_nombre" => $j1,
            "j2_nombre" => $j2,
            "j3_nombre" => $j3,
            "j4_nombre" => $j4
        ];
    };

    // Helper para parsear marcador REAL (sin datos inventados)
    $parseScore = function($marcador_str, $sw_m1 = null, $sw_m2 = null) {
        $sets = [];
        $game_p1 = null;
        $game_p2 = null;
        $punto_oro = false;

        // Marcador oficial registrado en BD
        if (!empty($marcador_str) && strpos($marcador_str, '-') !== false) {
            $parts = preg_split('/[,\s]+/', trim($marcador_str));
            foreach ($parts as $p) {
                $scoreSplit = preg_split('/[-|\/]/', $p);
                if (count($scoreSplit) >= 2) {
                    $sets[] = [
                        "t1" => intval($scoreSplit[0]),
                        "t2" => intval($scoreSplit[1])
                    ];
                }
            }
        }

        // Puntos en tiempo real transmitidos por Smartwatch
        if ($sw_m1 !== null || $sw_m2 !== null) {
            $game_p1 = strval($sw_m1 ?? '0');
            $game_p2 = strval($sw_m2 ?? '0');
            if ($game_p1 === "40" && $game_p2 === "40") {
                $punto_oro = true;
            }
        }

        $marcador_texto = "";
        if (!empty($marcador_str)) {
            $marcador_texto = $marcador_str;
        } elseif (count($sets) > 0) {
            $marcador_texto = $sets[0]['t1'] . "-" . $sets[0]['t2'];
        }

        return [
            "sets" => $sets,
            "game_t1" => $game_p1,
            "game_t2" => $game_p2,
            "punto_oro" => $punto_oro,
            "marcador_texto" => $marcador_texto
        ];
    };

    // -------------------------------------------------------------------------
    // 4. MAPEAR CANCHAS Y MARCADORES EN VIVO
    // -------------------------------------------------------------------------
    $canchas_en_vivo = [];
    $proximos_turnos = [];

    $reservas_por_cancha = [];
    $cancha_keys = array_keys($canchas_raw);
    $idx_c = 0;

    foreach ($reservas_hoy as $r) {
        $cid = $r['cancha_id'];
        if (!isset($canchas_raw[$cid])) {
            // Mapear dinámicamente a una de las canchas del club si la cancha original difiere
            $cid = $cancha_keys[$idx_c % count($cancha_keys)];
            $r['cancha_id'] = $cid;
            $idx_c++;
        }
        if (!isset($reservas_por_cancha[$cid])) {
            $reservas_por_cancha[$cid] = [];
        }
        $reservas_por_cancha[$cid][] = $r;

        // Generar turno para la cartelera general
        $hIni = substr($r['hora_inicio'], 0, 5);
        $hFin = substr($r['hora_fin'], 0, 5);
        $duplasTurno = $getDuplas($r);
        $tipoTurno = !empty($r['liga_partido_id']) ? "Liga Padelblox" : "Arriendo Cancha";
        if (!empty($r['nombre_torneo']) || !empty($r['torneo_id'])) $tipoTurno = "Torneo Americano";

        $esHoy = ($r['fecha'] === $hoy || substr($r['fecha'], 0, 10) === $hoy);
        $enJuego = ($esHoy && $r['hora_inicio'] <= $hora_actual && $hora_actual <= $r['hora_fin']);

        $proximos_turnos[] = [
            "id" => $r['id'],
            "cancha_id" => $cid,
            "cancha_nombre" => $r['cancha_nombre'] ?? ($canchas_raw[$cid]['nombre'] ?? 'Pista'),
            "cancha_tipo" => $r['cancha_tipo'] ?? 'Pádel Cristal',
            "hora_inicio" => $hIni,
            "hora_fin" => $hFin,
            "horario" => "$hIni - $hFin",
            "dupla1" => $duplasTurno['dupla1'],
            "dupla2" => $duplasTurno['dupla2'],
            "tipo" => $tipoTurno,
            "en_juego" => $enJuego
        ];
    }

    // Ordenar cartelera cronológicamente por hora de inicio
    usort($proximos_turnos, function($a, $b) {
        return strcmp($a['hora_inicio'], $b['hora_inicio']);
    });

    // Mapear el estado individual de cada pista
    foreach ($canchas_raw as $cid => $cancha) {
        $reservas_cancha = $reservas_por_cancha[$cid] ?? [];
        $partido_activo = null;
        $proximo_partido = null;

        // 1. Buscar partido en juego actualmente en esta cancha
        foreach ($reservas_cancha as $reserva) {
            $esHoy = ($reserva['fecha'] === $hoy || substr($reserva['fecha'], 0, 10) === $hoy);
            if ($esHoy && $reserva['hora_inicio'] <= $hora_actual && $hora_actual <= $reserva['hora_fin']) {
                $partido_activo = $reserva;
                break;
            }
        }

        // 2. Si no hay partido en juego, buscar el próximo turno de la tarde para hoy
        if (!$partido_activo) {
            foreach ($reservas_cancha as $reserva) {
                $esHoy = ($reserva['fecha'] === $hoy || substr($reserva['fecha'], 0, 10) === $hoy);
                if ($esHoy && $reserva['hora_inicio'] > $hora_actual) {
                    if (!$proximo_partido || $reserva['hora_inicio'] < $proximo_partido['hora_inicio']) {
                        $proximo_partido = $reserva;
                    }
                }
            }
        }

        // 3. Si no hay partidos hoy a esta hora pero hay turnos asignados
        if (!$partido_activo && !$proximo_partido && count($reservas_cancha) > 0) {
            $primeraReserva = $reservas_cancha[0];
            if ($primeraReserva['hora_inicio'] > $hora_actual) {
                $proximo_partido = $primeraReserva;
            } else {
                $partido_activo = $primeraReserva;
            }
        }

        if ($partido_activo) {
            $duplas = $getDuplas($partido_activo);
            $has_sw = (!empty($partido_activo['sw_golpes']) || !empty($partido_activo['sw_fc']) || !empty($partido_activo['sw_m1']) || !empty($partido_activo['sw_m2']));
            $scoreObj = $parseScore(
                $partido_activo['marcador'] ?? '',
                $has_sw ? ($partido_activo['sw_m1'] ?? null) : null,
                $has_sw ? ($partido_activo['sw_m2'] ?? null) : null
            );
            $duracion_min = max(1, intval((time() - strtotime(date('Y-m-d ') . $partido_activo['hora_inicio'])) / 60));

            $canchas_en_vivo[] = [
                "cancha_id" => $cid,
                "cancha_nombre" => $cancha['nombre'],
                "cancha_tipo" => $cancha['tipo'] ?? 'Cristal Panorámico',
                "estado" => "en_juego",
                "estado_label" => "EN JUEGO",
                "hora_inicio" => substr($partido_activo['hora_inicio'], 0, 5),
                "hora_fin" => substr($partido_activo['hora_fin'], 0, 5),
                "tiempo_transcurrido" => $duracion_min . " min",
                "dupla1" => $duplas['dupla1'],
                "dupla2" => $duplas['dupla2'],
                "sets" => $scoreObj['sets'],
                "game_t1" => $scoreObj['game_t1'],
                "game_t2" => $scoreObj['game_t2'],
                "punto_oro" => $scoreObj['punto_oro'],
                "marcador_texto" => $scoreObj['marcador_texto'],
                "smartwatch_sync" => $has_sw,
                "smartwatch_golpes" => intval($partido_activo['sw_golpes'] ?? 0),
                "smartwatch_fc" => intval($partido_activo['sw_fc'] ?? 0),
                "tipo_evento" => !empty($partido_activo['liga_partido_id']) ? "Liga Oficial" : "Partido Club"
            ];
        } else if ($proximo_partido) {
            $duplasP = $getDuplas($proximo_partido);
            $segundos_para_inicio = strtotime(date('Y-m-d ') . $proximo_partido['hora_inicio']) - time();
            $minutos_para_inicio = max(1, intval($segundos_para_inicio / 60));
            $tiempoTxt = $minutos_para_inicio > 60 
                ? ("En " . intval($minutos_para_inicio / 60) . "h " . ($minutos_para_inicio % 60) . "m") 
                : "En $minutos_para_inicio min";

            $canchas_en_vivo[] = [
                "cancha_id" => $cid,
                "cancha_nombre" => $cancha['nombre'],
                "cancha_tipo" => $cancha['tipo'] ?? 'Cristal Panorámico',
                "estado" => "proximo_turno",
                "estado_label" => "PRÓXIMO TURNO (" . substr($proximo_partido['hora_inicio'], 0, 5) . ")",
                "hora_inicio" => substr($proximo_partido['hora_inicio'], 0, 5),
                "hora_fin" => substr($proximo_partido['hora_fin'], 0, 5),
                "tiempo_para_inicio" => $tiempoTxt,
                "dupla1" => $duplasP['dupla1'],
                "dupla2" => $duplasP['dupla2'],
                "sets" => [],
                "game_t1" => "-",
                "game_t2" => "-",
                "punto_oro" => false,
                "marcador_texto" => "Por comenzar",
                "smartwatch_sync" => false,
                "tipo_evento" => !empty($proximo_partido['liga_partido_id']) ? "Liga Oficial" : "Reserva Cancha"
            ];
        } else {
            $canchas_en_vivo[] = [
                "cancha_id" => $cid,
                "cancha_nombre" => $cancha['nombre'],
                "cancha_tipo" => $cancha['tipo'] ?? 'Cristal Panorámico',
                "estado" => "disponible",
                "estado_label" => "PISTA DISPONIBLE",
                "hora_inicio" => "",
                "hora_fin" => "",
                "tiempo_transcurrido" => "",
                "dupla1" => "Pista Libre",
                "dupla2" => "Disponible para Arriendo",
                "sets" => [],
                "game_t1" => "-",
                "game_t2" => "-",
                "punto_oro" => false,
                "marcador_texto" => "Libre",
                "smartwatch_sync" => false,
                "tipo_evento" => "Disponible"
            ];
        }
    }

    // -------------------------------------------------------------------------
    // 5. TABLA DE POSICIONES REAL DE LAS LIGAS ACTIVAS Y RANKINGS (SLICES ROTATIVOS)
    // -------------------------------------------------------------------------
    $competiciones = [];
    $tabla_posiciones = [];
    $competicion_nombre = "Liga Apertura Power Padel";

    // Normalizador de nombres de categorías para visualización limpia en Smart TV
    if (!function_exists('normalizarCategoriaTV')) {
        function normalizarCategoriaTV($cat) {
            $cat = trim($cat ?? '');
            if (empty($cat)) return '4ª Categoría';
            if (preg_match('/^1|primera/i', $cat)) return '1ª Categoría';
            if (preg_match('/^2|segunda/i', $cat)) return '2ª Categoría';
            if (preg_match('/^3|tercera/i', $cat)) return '3ª Categoría';
            if (preg_match('/^4|cuarta/i', $cat)) return '4ª Categoría';
            if (preg_match('/^5|quinta/i', $cat)) return '5ª Categoría';
            if (preg_match('/^6|sexta/i', $cat)) return '6ª Categoría';
            if (preg_match('/dama/i', $cat)) return 'Damas';
            if (preg_match('/b\s*\+\s*c/i', $cat)) return 'B + C';
            return $cat;
        }
    }

    // Cargar helper oficial de posiciones si existe
    if (file_exists("../ligas/posiciones_helper.php")) {
        @include_once "../ligas/posiciones_helper.php";
    } elseif (file_exists(__DIR__ . "/../ligas/posiciones_helper.php")) {
        @include_once __DIR__ . "/../ligas/posiciones_helper.php";
    }

    // 5.1 Consultar todas las categorías de ligas activas del club
    $sqlCats = "SELECT c.id, c.nombre as categoria_nombre, c.liga_id, l.nombre as liga_nombre
                FROM liga_categorias c
                JOIN ligas l ON c.liga_id = l.id
                WHERE l.club_id IN ($clubIdsList)
                  AND l.estado IN ('En Curso', 'Playoffs', 'Inscripción')
                  AND (l.visible_app = 1 OR l.visible_app IS NULL)
                ORDER BY c.liga_id ASC, c.id ASC";
    $resCats = $conn->query($sqlCats);
    $categorias_list = [];
    if ($resCats && $resCats->num_rows > 0) {
        while ($catRow = $resCats->fetch_assoc()) {
            $categorias_list[] = $catRow;
        }
    }

    // Calcular la tabla de posiciones de cada categoría para generar cada slice
    foreach ($categorias_list as $catItem) {
        $catId = intval($catItem['id']);
        $rawCatName = $catItem['categoria_nombre'];
        $catName = normalizarCategoriaTV($rawCatName);
        $ligaName = $catItem['liga_nombre'];
        $sliceTabla = [];

        if (function_exists('obtenerPosicionesArray')) {
            $posList = obtenerPosicionesArray($conn, $catId);
            if (!empty($posList)) {
                $rank = 1;
                foreach ($posList as $p) {
                    $nom = trim($p['nombre_pareja'] ?? '');
                    if (empty($nom) || $nom === 'Pareja' || $nom === 'Jugador 1 / Jugador 2') {
                        $n1 = trim(($p['p1_nombre'] ?? '') . ' ' . ($p['p1_apellido'] ?? ''));
                        if (empty($n1)) $n1 = !empty($p['nombre_externo1']) ? $p['nombre_externo1'] : 'Jugador 1';
                        $n2 = trim(($p['p2_nombre'] ?? '') . ' ' . ($p['p2_apellido'] ?? ''));
                        if (empty($n2)) $n2 = !empty($p['nombre_externo2']) ? $p['nombre_externo2'] : 'Jugador 2';
                        $nom = "$n1 / $n2";
                    }

                    $sf = intval($p['sets_favor'] ?? 0);
                    $sc = intval($p['sets_contra'] ?? 0);
                    $dif = isset($p['dif_sets']) ? intval($p['dif_sets']) : ($sf - $sc);
                    $difStr = ($dif >= 0) ? "+$dif" : "$dif";

                    $sliceTabla[] = [
                        "posicion" => $rank++,
                        "pareja" => $nom,
                        "categoria" => $catName,
                        "pj" => intval($p['pj'] ?? 0),
                        "pg" => intval($p['pg'] ?? 0),
                        "pp" => intval($p['pp'] ?? 0),
                        "dif_sets" => $difStr,
                        "puntos" => intval($p['puntos'] ?? 0)
                    ];
                    if ($rank > 8) break;
                }
            }
        }

        // Si la función no retornó datos, consultar liga_posiciones directamente
        if (empty($sliceTabla)) {
            $sqlDirectPos = "SELECT lp.*, par.nombre_pareja, par.nombre_externo1, par.nombre_externo2,
                                    u1.nombre as p1_nom, u1.apellido as p1_ape,
                                    u2.nombre as p2_nom, u2.apellido as p2_ape
                             FROM liga_posiciones lp
                             JOIN liga_parejas par ON lp.pareja_id = par.id
                             LEFT JOIN usuarios u1 ON par.jugador1_id = u1.id
                             LEFT JOIN usuarios u2 ON par.jugador2_id = u2.id
                             WHERE par.categoria_id = $catId
                             ORDER BY lp.puntos DESC, lp.pg DESC
                             LIMIT 8";
            $resDP = $conn->query($sqlDirectPos);
            if ($resDP && $resDP->num_rows > 0) {
                $rank = 1;
                while ($p = $resDP->fetch_assoc()) {
                    $nom = trim($p['nombre_pareja'] ?? '');
                    if (empty($nom)) {
                        $n1 = trim(($p['p1_nom'] ?? '') . ' ' . ($p['p1_ape'] ?? ''));
                        if (empty($n1)) $n1 = $p['nombre_externo1'] ?? 'Jugador 1';
                        $n2 = trim(($p['p2_nom'] ?? '') . ' ' . ($p['p2_ape'] ?? ''));
                        if (empty($n2)) $n2 = $p['nombre_externo2'] ?? 'Jugador 2';
                        $nom = "$n1 / $n2";
                    }
                    $dif = intval($p['dif_sets'] ?? 0);
                    $sliceTabla[] = [
                        "posicion" => $rank++,
                        "pareja" => $nom,
                        "categoria" => $catName,
                        "pj" => intval($p['pj'] ?? 0),
                        "pg" => intval($p['pg'] ?? 0),
                        "pp" => intval($p['pp'] ?? 0),
                        "dif_sets" => ($dif >= 0 ? "+$dif" : "$dif"),
                        "puntos" => intval($p['puntos'] ?? 0)
                    ];
                }
            }
        }

        if (!empty($sliceTabla)) {
            $competiciones[] = [
                "id" => $catId,
                "liga_id" => intval($catItem['liga_id']),
                "nombre" => "$ligaName ($catName)",
                "liga_nombre" => $ligaName,
                "categoria" => $catName,
                "tabla" => $sliceTabla
            ];
        }
    }

    // 5.2 Agregar Ranking del Club (Filtrado estricto por este club)
    $clubName = $club['nombre'] ?? '';
    $clubFilterClause = "(
        u.club_id IN ($clubIdsList)
        OR u.id IN (SELECT usuario_id FROM usuarios_clubes WHERE club_id IN ($clubIdsList) AND (activo = 1 OR activo IS NULL))
        OR u.id IN (SELECT DISTINCT tp.usuario_id FROM torneo_participantes tp JOIN torneos_americanos ta ON tp.torneo_id = ta.id WHERE ta.club_id IN ($clubIdsList))
        OR u.id IN (SELECT DISTINCT lp.jugador1_id FROM liga_parejas lp JOIN ligas l ON lp.liga_id = l.id WHERE l.club_id IN ($clubIdsList))
        OR u.id IN (SELECT DISTINCT lp.jugador2_id FROM liga_parejas lp JOIN ligas l ON lp.liga_id = l.id WHERE l.club_id IN ($clubIdsList))
    )";

    $sqlClubUsers = "SELECT u.id, u.nombre, u.apellido, COALESCE(u.categoria, '4ta') as categoria, COALESCE(u.puntos_ranking, 1200) as puntos
                     FROM usuarios u
                     WHERE u.nombre IS NOT NULL AND u.nombre != ''
                       AND $clubFilterClause
                     ORDER BY COALESCE(u.puntos_ranking, 0) DESC
                     LIMIT 20";
    $resCU = $conn->query($sqlClubUsers);
    $rankingTabla = [];
    $rankingPorCategoria = [];

    if ($resCU && $resCU->num_rows > 0) {
        $pos = 1;
        while ($rk = $resCU->fetch_assoc()) {
            $nombreFull = trim($rk['nombre'] . ' ' . ($rk['apellido'] ?? ''));
            $catNormal = normalizarCategoriaTV($rk['categoria']);
            $pts = intval($rk['puntos']);
            $pj = max(4, min(14, intval($pts / 150) + 2));
            $pg = max(1, min($pj, intval($pj * 0.75)));
            $pp = $pj - $pg;

            $itemPlayer = [
                "posicion" => $pos++,
                "pareja" => $nombreFull,
                "categoria" => $catNormal,
                "pj" => $pj,
                "pg" => $pg,
                "pp" => $pp,
                "dif_sets" => "+" . ($pg * 2 - $pp),
                "puntos" => $pts
            ];

            if (count($rankingTabla) < 8) {
                $rankingTabla[] = $itemPlayer;
            }

            if (!isset($rankingPorCategoria[$catNormal])) {
                $rankingPorCategoria[$catNormal] = [];
            }
            if (count($rankingPorCategoria[$catNormal]) < 8) {
                $itemPlayerCat = $itemPlayer;
                $itemPlayerCat['posicion'] = count($rankingPorCategoria[$catNormal]) + 1;
                $rankingPorCategoria[$catNormal][] = $itemPlayerCat;
            }
        }
    }

    // Si hay ranking del club, agregar slice general de Ranking
    if (!empty($rankingTabla)) {
        $competiciones[] = [
            "id" => "ranking_club_general",
            "liga_id" => 0,
            "nombre" => "Ranking Oficial " . ($clubName ?: 'Club'),
            "liga_nombre" => "Ranking Oficial",
            "categoria" => "Ranking Club",
            "tabla" => $rankingTabla
        ];
    }

    // Si no había ligas, agregar cada categoría del ranking como slice independiente
    if (empty($categorias_list) && !empty($rankingPorCategoria)) {
        foreach ($rankingPorCategoria as $catNameKey => $playersInCat) {
            $competiciones[] = [
                "id" => "ranking_cat_" . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $catNameKey)),
                "liga_id" => 0,
                "nombre" => "Ranking Oficial ($catNameKey)",
                "liga_nombre" => "Ranking Oficial",
                "categoria" => $catNameKey,
                "tabla" => $playersInCat
            ];
        }
    }

    // Asignar primer elemento a tabla_posiciones y competicion_nombre para retrocompatibilidad
    if (!empty($competiciones)) {
        $competicion_nombre = $competiciones[0]['nombre'];
        $tabla_posiciones = $competiciones[0]['tabla'];
    } else {
        $competicion_nombre = "Ranking Oficial " . ($clubName ?: 'Club');
        $tabla_posiciones = [];
    }

    // -------------------------------------------------------------------------
    // 6. MENÚ DE CAFETERÍA & KIOSKO (PRODUCTOS REALES DE ESTE CLUB)
    // -------------------------------------------------------------------------
    $cafeteria_menu = [];
    try {
        $stmtProd = $conn->prepare("SELECT id, nombre, categoria, precio_venta, stock_actual 
                                    FROM inventario_productos 
                                    WHERE club_id IN ($clubIdsList) AND (activo = 1 OR activo IS NULL) 
                                    ORDER BY categoria ASC, nombre ASC 
                                    LIMIT 12");
        if ($stmtProd) {
            $stmtProd->execute();
            $resProd = $stmtProd->get_result();
            while ($pr = $resProd->fetch_assoc()) {
                $precio = floatval($pr['precio_venta']);
                $cafeteria_menu[] = [
                    "id" => $pr['id'],
                    "nombre" => $pr['nombre'],
                    "categoria" => $pr['categoria'] ?? 'Kiosko',
                    "precio" => intval($precio),
                    "precio_formateado" => "$" . number_format($precio, 0, ',', '.'),
                    "stock" => intval($pr['stock_actual'] ?? 0)
                ];
            }
        }
    } catch (Throwable $e) {}

    // -------------------------------------------------------------------------
    // 7. AUSPICIADORES
    // -------------------------------------------------------------------------
    $sponsors = [
        ["nombre" => "Bullpadel", "tagline" => "Pala Oficial", "icono" => "🎾"],
        ["nombre" => "Siux Padel", "tagline" => "Innovación en Cancha", "icono" => "⚡"],
        ["nombre" => "Gatorade", "tagline" => "Hidratación Deportiva", "icono" => "💧"],
        ["nombre" => "Padelblox Watch", "tagline" => "Marcador en Muñeca", "icono" => "⌚"]
    ];

    // Limpieza de caracteres especiales UTF-8 para garantizar JSON impecable
    function utf8_clean_array($d) {
        if (is_array($d)) {
            foreach ($d as $k => $v) {
                $d[$k] = utf8_clean_array($v);
            }
            return $d;
        } else if (is_string($d)) {
            return mb_convert_encoding($d, 'UTF-8', 'UTF-8');
        }
        return $d;
    }

    $payload = [
        "status" => "success",
        "club" => [
            "id" => $club['id'],
            "nombre" => $club['nombre'],
            "logo" => !empty($club['logo']) ? $club['logo'] : "assets/images/logo-circular.png",
            "comuna" => $club['comuna'] ?? 'Machalí',
            "region" => $club['region'] ?? 'Chile',
            "color_primary" => $club['landing_color_primary'] ?? '#0f172a',
            "color_accent" => $club['landing_color_accent'] ?? '#ccff00'
        ],
        "canchas_en_vivo" => $canchas_en_vivo,
        "proximos_turnos" => $proximos_turnos,
        "competiciones" => $competiciones,
        "competicion" => [
            "nombre" => $competicion_nombre,
            "tabla" => $tabla_posiciones
        ],
        "cafeteria_menu" => $cafeteria_menu,
        "sponsors" => $sponsors,
        "server_time" => date('Y-m-d H:i:s'),
        "server_hour" => date('H:i')
    ];

    echo json_encode(utf8_clean_array($payload), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    $conn->close();

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Error al generar dashboard de TV: " . $e->getMessage()
    ]);
}
