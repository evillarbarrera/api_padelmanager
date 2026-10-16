<?php
ini_set('display_errors', '0');
error_reporting(0);
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
mysqli_report(MYSQLI_REPORT_OFF);
require_once "../db.php";

$input = json_decode(file_get_contents("php://input"), true);

$jornada_ids = [];
if (!empty($input['jornada_ids']) && is_array($input['jornada_ids'])) {
    $jornada_ids = array_map('intval', $input['jornada_ids']);
} elseif (!empty($input['jornada_id'])) {
    $jornada_ids = [(int)$input['jornada_id']];
}

$fecha_inicio = !empty($input['fecha_inicio']) ? trim($input['fecha_inicio']) : date('Y-m-d');
$dias_semana = !empty($input['dias_semana']) && is_array($input['dias_semana']) ? $input['dias_semana'] : ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
$horarios_raw = !empty($input['horarios']) && is_array($input['horarios']) ? $input['horarios'] : ['18:00', '19:30', '21:00'];
$canchas_ids = !empty($input['canchas_ids']) && is_array($input['canchas_ids']) ? array_values(array_unique(array_filter(array_map('intval', $input['canchas_ids'])))) : [];
$respetar_restricciones = isset($input['respetar_restricciones']) ? (bool)$input['respetar_restricciones'] : true;
$max_partidos_por_pareja = isset($input['max_partidos_por_pareja']) ? (int)$input['max_partidos_por_pareja'] : 1;
$duracion_minutos = !empty($input['duracion_minutos']) ? (int)$input['duracion_minutos'] : (!empty($input['duracion']) ? (int)$input['duracion'] : 90);
if ($duracion_minutos < 30) $duracion_minutos = 90;
$duracion_segundos = $duracion_minutos * 60;

// Limpiar y ordenar horarios únicos en formato HH:MM
$horarios = [];
foreach ($horarios_raw as $h) {
    $hClean = substr(trim((string)$h), 0, 5);
    if (preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $hClean)) {
        $horarios[] = sprintf('%02d:%02d', (int)substr($hClean, 0, 2), (int)substr($hClean, 3, 2));
    }
}
$horarios = array_values(array_unique($horarios));
sort($horarios);
if (empty($horarios)) {
    $horarios = ['18:00', '19:30', '21:00'];
}

if (empty($jornada_ids)) {
    echo json_encode(["success" => false, "error" => "ID de jornada requerido"]);
    exit;
}

// Asegurar columna liga_partido_id en reservas_cancha
$check_col = $conn->query("SHOW COLUMNS FROM reservas_cancha LIKE 'liga_partido_id'");
if ($check_col && $check_col->num_rows == 0) {
    $conn->query("ALTER TABLE reservas_cancha ADD `liga_partido_id` INT NULL");
}

function tieneRestriccion($restriccionesJson, $diaNombre, $horaStr) {
    if (empty($restriccionesJson)) return false;
    $list = is_string($restriccionesJson) ? json_decode($restriccionesJson, true) : $restriccionesJson;
    if (!is_array($list)) return false;

    $targetTime = strtotime("1970-01-01 " . $horaStr);
    foreach ($list as $r) {
        if (!empty($r['dia']) && strcasecmp($r['dia'], $diaNombre) === 0) {
            $hIni = !empty($r['hora_inicio']) ? strtotime("1970-01-01 " . $r['hora_inicio']) : null;
            $hFin = !empty($r['hora_fin']) ? strtotime("1970-01-01 " . $r['hora_fin']) : null;
            if ($hIni !== null && $hFin !== null && $targetTime >= $hIni && $targetTime < $hFin) {
                return true;
            }
        }
    }
    return false;
}

try {
    // 1. Obtener los partidos de las jornadas con los 4 jugadores de ambas parejas
    $j_ids_str = implode(',', $jornada_ids);
    $sql_partidos = "SELECT p.id, p.pareja1_id, p.pareja2_id,
                            cat.dias_juego_json as cat_dias_restr,
                            par1.nombre_pareja as p1_nom, par2.nombre_pareja as p2_nom,
                            par1.jugador1_id as p1_j1_id, par1.jugador2_id as p1_j2_id,
                            par2.jugador1_id as p2_j1_id, par2.jugador2_id as p2_j2_id,
                            par1.nombre_externo1 as p1_j1_ext, par1.nombre_externo2 as p1_j2_ext,
                            par2.nombre_externo1 as p2_j1_ext, par2.nombre_externo2 as p2_j2_ext,
                            u11.nombre as p1_j1_u, u12.nombre as p1_j2_u,
                            u21.nombre as p2_j1_u, u22.nombre as p2_j2_u,
                            par1.restricciones_horarias_json as p1_restr,
                            par2.restricciones_horarias_json as p2_restr
                     FROM liga_partidos p
                     LEFT JOIN liga_jornadas j ON p.jornada_id = j.id
                     LEFT JOIN liga_categorias cat ON (j.categoria_id = cat.id OR p.categoria_id = cat.id)
                     LEFT JOIN liga_parejas par1 ON p.pareja1_id = par1.id
                     LEFT JOIN liga_parejas par2 ON p.pareja2_id = par2.id
                     LEFT JOIN usuarios u11 ON par1.jugador1_id = u11.id
                     LEFT JOIN usuarios u12 ON par1.jugador2_id = u12.id
                     LEFT JOIN usuarios u21 ON par2.jugador1_id = u21.id
                     LEFT JOIN usuarios u22 ON par2.jugador2_id = u22.id
                     WHERE p.jornada_id IN ($j_ids_str) AND p.estado != 'Jugado'
                     ORDER BY p.id ASC";
    $res_m = $conn->query($sql_partidos);
    $partidos = [];
    if ($res_m) {
        while ($m = $res_m->fetch_assoc()) {
            $partidos[] = $m;
        }
    }

    if (empty($partidos)) {
        echo json_encode(["success" => false, "error" => "No hay partidos pendientes para programar en esta jornada."]);
        exit;
    }

    // Asegurar esquema de columnas para reservas de cancha de liga
    $c3 = $conn->query("SHOW COLUMNS FROM reservas_cancha LIKE 'nombre_externo3'");
    if ($c3 && $c3->num_rows == 0) {
        $conn->query("ALTER TABLE reservas_cancha ADD COLUMN `nombre_externo3` VARCHAR(255) NULL");
    }
    $c4 = $conn->query("SHOW COLUMNS FROM reservas_cancha LIKE 'nombre_externo4'");
    if ($c4 && $c4->num_rows == 0) {
        $conn->query("ALTER TABLE reservas_cancha ADD COLUMN `nombre_externo4` VARCHAR(255) NULL");
    }
    $cPt = $conn->query("SHOW COLUMNS FROM reservas_cancha LIKE 'precio_total'");
    if ($cPt && $cPt->num_rows == 0) {
        $conn->query("ALTER TABLE reservas_cancha ADD COLUMN `precio_total` DECIMAL(10,2) DEFAULT 0");
    }
    $conn->query("UPDATE reservas_cancha SET precio = 0, precio_total = 0 WHERE liga_partido_id IS NOT NULL AND liga_partido_id > 0");

    // Mezclar aleatoriamente los partidos de distintas categorías para distribuirlos de forma intercalada entre todas las canchas
    shuffle($partidos);

    $partidosIdsArr = array_map(function($p) { return (int)$p['id']; }, $partidos);
    $partidosIdsList = implode(',', $partidosIdsArr);

    // Limpieza previa atómica: eliminar reservas en reservas_cancha de estos partidos antes de reprogramar
    $conn->query("DELETE FROM reservas_cancha WHERE liga_partido_id IN ($partidosIdsList)");
    // Resetear partidos inicialmente para que los que no encuentren cupo queden limpiamente sin fecha/cancha
    $conn->query("UPDATE liga_partidos SET cancha_id = NULL, fecha_hora = NULL WHERE id IN ($partidosIdsList)");

    // Si NO se pasaron canchas específicas desde el frontend, buscar las canchas del club asociado
    if (empty($canchas_ids)) {
        $res_c = $conn->query("SELECT DISTINCT c.id FROM canchas c 
                               JOIN ligas l ON (c.club_id = l.club_id OR l.club_id IS NULL OR l.club_id = 0)
                               JOIN liga_categorias cat ON cat.liga_id = l.id
                               JOIN liga_jornadas j ON j.categoria_id = cat.id
                               WHERE j.id IN ($j_ids_str) AND c.activa = 1");
        if ($res_c && $res_c->num_rows > 0) {
            while ($row_c = $res_c->fetch_assoc()) {
                $canchas_ids[] = (int)$row_c['id'];
            }
        }
    }

    if (empty($canchas_ids)) {
        $res_all = $conn->query("SELECT id FROM canchas WHERE activa = 1 ORDER BY id ASC");
        if ($res_all) {
            while ($r = $res_all->fetch_assoc()) {
                $canchas_ids[] = (int)$r['id'];
            }
        }
    }

    if (empty($canchas_ids)) {
        echo json_encode(["success" => false, "error" => "No hay canchas registradas o seleccionadas para asignar los partidos."]);
        exit;
    }
    $canchasIdsList = implode(',', $canchas_ids);

    // Mezclar aleatoriamente el orden de los partidos para distribución equilibrada entre categorías
    shuffle($partidos);

    // 2. Definir ventana de días de evaluación (7 días a partir de la fecha de inicio)
    $diasEs = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    $inicioTimestamp = strtotime($fecha_inicio);
    $diasLoop = 7;
    $fIniDate = date('Y-m-d', $inicioTimestamp);
    $fFinDate = date('Y-m-d', strtotime("+" . ($diasLoop - 1) . " days", $inicioTimestamp));

    // Estructurar los días habilitados de la semana en orden cronológico
    $diasHabilitados = [];
    for ($d = 0; $d < $diasLoop; $d++) {
        $curTs = strtotime("+$d days", $inicioTimestamp);
        $curDate = date('Y-m-d', $curTs);
        $w = (int)date('w', $curTs);
        $diaNombre = $diasEs[$w];

        if (in_array($diaNombre, $dias_semana)) {
            $diasHabilitados[] = [
                'offset' => $d,
                'fecha' => $curDate,
                'dia_nombre' => $diaNombre
            ];
        }
    }

    if (empty($diasHabilitados)) {
        echo json_encode(["success" => false, "error" => "Los días de la semana seleccionados no coinciden con la fecha de inicio ingresada."]);
        exit;
    }

    // 3. Pre-cargar TODOS los intervalos ocupados en las canchas seleccionadas durante la ventana
    // Esto incluye: Americanos, reservas de clientes, clases de profesores, bloqueos manuales y otros partidos de liga
    $canchaBusy = []; // [$fecha][$cancha_id][] = ['start' => min, 'end' => min, 'tipo' => string]
    $sqlBusy = "SELECT cancha_id, fecha, hora_inicio, hora_fin, estado, tipo_reserva, torneo_id, liga_partido_id 
                FROM reservas_cancha 
                WHERE cancha_id IN ($canchasIdsList)
                  AND fecha >= '$fIniDate' AND fecha <= '$fFinDate'
                  AND LOWER(TRIM(estado)) NOT IN ('cancelada', 'cancelado', 'anulada', 'anulado', 'rechazada', 'rechazado', 'eliminada', 'eliminado', 'inactiva', 'inactivo', 'expirada', 'expirado')";
    $resBusy = $conn->query($sqlBusy);
    if ($resBusy) {
        while ($bRow = $resBusy->fetch_assoc()) {
            $cId = (int)$bRow['cancha_id'];
            $fDate = substr(trim((string)$bRow['fecha']), 0, 10);
            $hIni = substr(trim((string)$bRow['hora_inicio']), 0, 5);
            $hFin = substr(trim((string)$bRow['hora_fin']), 0, 5);

            $partsIni = explode(':', $hIni);
            $sMin = ((int)($partsIni[0] ?? 0) * 60) + (int)($partsIni[1] ?? 0);

            if (in_array($hFin, ['00:00', '24:00', '23:59', '23:59:00', '24:00:00']) || empty($hFin)) {
                $eMin = 1440;
            } else {
                $partsFin = explode(':', $hFin);
                $eMin = ((int)($partsFin[0] ?? 0) * 60) + (int)($partsFin[1] ?? 0);
                if ($eMin <= $sMin) {
                    $eMin = 1440;
                }
            }

            if (!isset($canchaBusy[$fDate])) $canchaBusy[$fDate] = [];
            if (!isset($canchaBusy[$fDate][$cId])) $canchaBusy[$fDate][$cId] = [];
            $canchaBusy[$fDate][$cId][] = [
                'start' => $sMin,
                'end' => $eMin,
                'tipo' => $bRow['tipo_reserva'] ?: $bRow['estado']
            ];
        }
    }

    // 3.1 Cargar también directamente desde la tabla torneos_americanos para blindaje total contra solapamientos
    $sqlTa = "SELECT id, club_id, nombre, fecha, hora_inicio, num_canchas, tiempo_por_partido, max_parejas, tipo_torneo 
              FROM torneos_americanos 
              WHERE fecha >= '$fIniDate' AND fecha <= '$fFinDate' 
                AND (estado = 'Abierto' OR estado IS NULL)";
    $resTa = $conn->query($sqlTa);
    if ($resTa && $resTa->num_rows > 0) {
        while ($ta = $resTa->fetch_assoc()) {
            $fDateTa = substr(trim((string)$ta['fecha']), 0, 10);
            $num_c = max(1, (int)$ta['num_canchas']);
            $t_part = max(10, (int)$ta['tiempo_por_partido']);
            $max_p = max(4, (int)$ta['max_parejas']);
            $tipo_t = $ta['tipo_torneo'] ?? 'estandar';

            if ($tipo_t === 'grupos' && $max_p === 8) {
                $total_partidos = 14;
            } else {
                $total_partidos = ($max_p * ($max_p - 1)) / 2;
            }
            $slots = ceil($total_partidos / $num_c);
            $duracion_min = max(90, min(360, (int)ceil(($slots * $t_part) / 30) * 30));

            $start_ts = strtotime($ta['hora_inicio']);
            $sMin = ((int)date('H', $start_ts) * 60) + (int)date('i', $start_ts);
            $eMin = $sMin + $duracion_min;

            $tId = (int)$ta['id'];
            $chkTaRes = $conn->query("SELECT DISTINCT cancha_id FROM reservas_cancha WHERE torneo_id = $tId AND fecha = '$fDateTa'");
            $taCanchas = [];
            if ($chkTaRes) {
                while ($rTa = $chkTaRes->fetch_assoc()) {
                    $taCanchas[] = (int)$rTa['cancha_id'];
                }
            }
            if (empty($taCanchas)) {
                $cidClub = (int)($ta['club_id'] ?? 0);
                $resCta = $conn->query("SELECT id FROM canchas WHERE club_id = $cidClub AND activa = 1 ORDER BY id ASC LIMIT $num_c");
                if ($resCta) {
                    while ($rCta = $resCta->fetch_assoc()) {
                        $taCanchas[] = (int)$rCta['id'];
                    }
                }
            }

            if (!isset($canchaBusy[$fDateTa])) $canchaBusy[$fDateTa] = [];
            foreach ($taCanchas as $cIdTa) {
                if (!isset($canchaBusy[$fDateTa][$cIdTa])) $canchaBusy[$fDateTa][$cIdTa] = [];
                $canchaBusy[$fDateTa][$cIdTa][] = [
                    'start' => $sMin,
                    'end' => $eMin,
                    'tipo' => 'Americano'
                ];
            }
        }
    }

    // Función estricta para verificar colisión de intervalo en cancha (Cero Solapamientos)
    $isCourtSlotFree = function($fDate, $cId, $slotStartMin, $slotEndMin) use (&$canchaBusy) {
        if ($slotEndMin > 1440) return false; // No programar partidos que terminen después de medianoche
        $fDateClean = substr(trim((string)$fDate), 0, 10);
        if (empty($canchaBusy[$fDateClean][$cId])) return true;

        foreach ($canchaBusy[$fDateClean][$cId] as $busy) {
            // Solapamiento estricto de intervalos abiertos: StartA < EndB && EndA > StartB
            if ($slotStartMin < $busy['end'] && $slotEndMin > $busy['start']) {
                return false;
            }
        }
        return true;
    };

    // 4. Pre-cargar descansos y partidos ya asignados a parejas y jugadores en otras ligas o categorías
    $parejaBusyTs = [];  // [$parejaId][] = ['start' => ts, 'end' => ts]
    $jugadorBusyTs = []; // [$userId][]   = ['start' => ts, 'end' => ts]
    $parejaMatchesCount = [];

    $sqlExPart = "SELECT p.id, p.cancha_id, p.fecha_hora, p.pareja1_id, p.pareja2_id,
                         par1.jugador1_id as p1_j1, par1.jugador2_id as p1_j2,
                         par2.jugador1_id as p2_j1, par2.jugador2_id as p2_j2
                  FROM liga_partidos p
                  LEFT JOIN liga_parejas par1 ON p.pareja1_id = par1.id
                  LEFT JOIN liga_parejas par2 ON p.pareja2_id = par2.id
                  WHERE p.fecha_hora >= '$fIniDate 00:00:00' AND p.fecha_hora <= '$fFinDate 23:59:59'
                    AND p.estado NOT IN ('Cancelado', 'Suspendido')
                    AND p.id NOT IN ($partidosIdsList)
                    AND p.fecha_hora IS NOT NULL";
    $resExPart = $conn->query($sqlExPart);
    if ($resExPart) {
        while ($rx = $resExPart->fetch_assoc()) {
            $ts = strtotime($rx['fecha_hora']);
            $tsEnd = $ts + $duracion_segundos;
            $restStart = $ts - 5400; // Al menos 90 min de descanso
            $restEnd = $tsEnd + 5400;

            // Bloquear la cancha si este partido ya tenía cancha asignada
            $pCanchaId = (int)($rx['cancha_id'] ?? 0);
            if ($pCanchaId > 0 && in_array($pCanchaId, $canchas_ids)) {
                $fDateEx = date('Y-m-d', $ts);
                $sMinEx = ((int)date('H', $ts) * 60) + (int)date('i', $ts);
                $eMinEx = $sMinEx + $duracion_minutos;
                if (!isset($canchaBusy[$fDateEx])) $canchaBusy[$fDateEx] = [];
                if (!isset($canchaBusy[$fDateEx][$pCanchaId])) $canchaBusy[$fDateEx][$pCanchaId] = [];
                $canchaBusy[$fDateEx][$pCanchaId][] = [
                    'start' => $sMinEx,
                    'end' => $eMinEx,
                    'tipo' => 'Otro Partido Liga'
                ];
            }

            if (!empty($rx['pareja1_id'])) $parejaBusyTs[(int)$rx['pareja1_id']][] = ['start' => $restStart, 'end' => $restEnd];
            if (!empty($rx['pareja2_id'])) $parejaBusyTs[(int)$rx['pareja2_id']][] = ['start' => $restStart, 'end' => $restEnd];
            if (!empty($rx['p1_j1'])) $jugadorBusyTs[(int)$rx['p1_j1']][] = ['start' => $restStart, 'end' => $restEnd];
            if (!empty($rx['p1_j2'])) $jugadorBusyTs[(int)$rx['p1_j2']][] = ['start' => $restStart, 'end' => $restEnd];
            if (!empty($rx['p2_j1'])) $jugadorBusyTs[(int)$rx['p2_j1']][] = ['start' => $restStart, 'end' => $restEnd];
            if (!empty($rx['p2_j2'])) $jugadorBusyTs[(int)$rx['p2_j2']][] = ['start' => $restStart, 'end' => $restEnd];
        }
    }

    $stmt_up = $conn->prepare("UPDATE liga_partidos SET cancha_id = ?, fecha_hora = ? WHERE id = ?");
    $stmt_res = $conn->prepare("INSERT INTO reservas_cancha (cancha_id, usuario_id, jugador2_id, jugador3_id, jugador4_id, nombre_externo, nombre_externo2, nombre_externo3, nombre_externo4, fecha, hora_inicio, hora_fin, precio, precio_total, pagado, estado, liga_partido_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 1, 'Confirmada', ?)");

    $programadosCount = 0;
    $partidosProgramadosIds = [];
    $partidosSinHorario = [];
    $matchIdx = 0;
    $totalDiasCount = count($diasHabilitados);

    // 5. Bucle Principal de Programación con Cascada de Días y Cero Solapamientos
    foreach ($partidos as &$p) {
        $p1Id = (int)($p['pareja1_id'] ?? 0);
        $p2Id = (int)($p['pareja2_id'] ?? 0);

        // Si se configuró un límite semanal por pareja y ya lo alcanzaron, dejar sin horario
        if ($max_partidos_por_pareja > 0) {
            if (($parejaMatchesCount[$p1Id] ?? 0) >= $max_partidos_por_pareja || ($parejaMatchesCount[$p2Id] ?? 0) >= $max_partidos_por_pareja) {
                $partidosSinHorario[] = [
                    'id' => (int)$p['id'],
                    'parejas' => ($p['p1_nom'] ?: 'P1') . ' vs ' . ($p['p2_nom'] ?: 'P2'),
                    'motivo' => 'Límite máximo semanal de partidos por pareja alcanzado (' . $max_partidos_por_pareja . ')'
                ];
                continue;
            }
        }

        $pJugadoresIds = array_filter([
            (int)($p['p1_j1_id'] ?? 0),
            (int)($p['p1_j2_id'] ?? 0),
            (int)($p['p2_j1_id'] ?? 0),
            (int)($p['p2_j2_id'] ?? 0)
        ]);

        $checkConflictoJugadores = function($slotTsStart, $slotTsEnd) use (&$parejaBusyTs, &$jugadorBusyTs, $p1Id, $p2Id, $pJugadoresIds) {
            if ($p1Id > 0 && !empty($parejaBusyTs[$p1Id])) {
                foreach ($parejaBusyTs[$p1Id] as $busy) {
                    if ($slotTsStart < $busy['end'] && $slotTsEnd > $busy['start']) return false;
                }
            }
            if ($p2Id > 0 && !empty($parejaBusyTs[$p2Id])) {
                foreach ($parejaBusyTs[$p2Id] as $busy) {
                    if ($slotTsStart < $busy['end'] && $slotTsEnd > $busy['start']) return false;
                }
            }
            foreach ($pJugadoresIds as $uId) {
                if (!empty($jugadorBusyTs[$uId])) {
                    foreach ($jugadorBusyTs[$uId] as $busy) {
                        if ($slotTsStart < $busy['end'] && $slotTsEnd > $busy['start']) return false;
                    }
                }
            }
            return true;
        };

        $catDias = !empty($p['cat_dias_restr']) ? json_decode($p['cat_dias_restr'], true) : [];
        if (!is_array($catDias)) $catDias = [];

        // Separar días en dos listas ordenadas:
        // 1. Días preferidos de la categoría
        // 2. Días restantes de la semana (para cascada si no hay cupos)
        $diasPref = [];
        $diasRestantes = [];

        for ($step = 0; $step < $totalDiasCount; $step++) {
            $diaObj = $diasHabilitados[$step];
            if (!empty($catDias) && in_array($diaObj['dia_nombre'], $catDias)) {
                $diasPref[] = $diaObj;
            } else {
                $diasRestantes[] = $diaObj;
            }
        }

        // Fases de búsqueda:
        // Intento 1: Días preferidos de la categoría respetando restricciones
        // Intento 2: Cascada a otros días habilitados respetando restricciones ("si no hay cupos los tire a otros dias")
        // Intento 3: Cascada general relajando restricciones horarias de jugadores si fuera estrictamente necesario
        $intentos = [];
        if (!empty($diasPref)) {
            $intentos[] = ['dias' => $diasPref, 'respetar_restr' => $respetar_restricciones];
            $intentos[] = ['dias' => $diasRestantes, 'respetar_restr' => $respetar_restricciones];
            $intentos[] = ['dias' => array_merge($diasPref, $diasRestantes), 'respetar_restr' => false];
        } else {
            $intentos[] = ['dias' => $diasHabilitados, 'respetar_restr' => $respetar_restricciones];
            $intentos[] = ['dias' => $diasHabilitados, 'respetar_restr' => false];
        }

        $slotAsignado = null;

        foreach ($intentos as $intento) {
            $listaDias = $intento['dias'];
            $evalRestr = $intento['respetar_restr'];

            foreach ($listaDias as $diaInfo) {
                $fDate = $diaInfo['fecha'];
                $dNom = $diaInfo['dia_nombre'];

                // Rotar canchas para distribuir carga equitativamente
                $numCanchas = count($canchas_ids);
                for ($cIdx = 0; $cIdx < $numCanchas; $cIdx++) {
                    $canchaId = $canchas_ids[($matchIdx + $cIdx) % $numCanchas];

                    foreach ($horarios as $horaStr) {
                        $parts = explode(':', $horaStr);
                        $slotStartMin = ((int)$parts[0] * 60) + (int)$parts[1];
                        $slotEndMin = $slotStartMin + $duracion_minutos;

                        // 1. Validar que la cancha esté 100% libre durante toda la duración (Sin Colisiones)
                        if (!$isCourtSlotFree($fDate, $canchaId, $slotStartMin, $slotEndMin)) {
                            continue;
                        }

                        // 2. Validar restricciones horarias declaradas por los jugadores
                        if ($evalRestr && (tieneRestriccion($p['p1_restr'], $dNom, $horaStr) || tieneRestriccion($p['p2_restr'], $dNom, $horaStr))) {
                            continue;
                        }

                        // 3. Validar descanso y no solapamiento con los 4 jugadores
                        $slotTsStart = strtotime("$fDate $horaStr:00");
                        $slotTsEnd = $slotTsStart + $duracion_segundos;
                        if (!$checkConflictoJugadores($slotTsStart, $slotTsEnd)) {
                            continue;
                        }

                        // ¡Cupo Libre Encontrado!
                        $slotAsignado = [
                            'cancha_id' => $canchaId,
                            'fecha' => $fDate,
                            'hora' => $horaStr,
                            'start_min' => $slotStartMin,
                            'end_min' => $slotEndMin,
                            'ts_start' => $slotTsStart,
                            'ts_end' => $slotTsEnd
                        ];
                        break 4; // Salir de intentos, días, canchas y horas
                    }
                }
            }
        }

        // Si se encontró cupo disponible sin solapamiento
        if ($slotAsignado) {
            $assignedCancha = (int)$slotAsignado['cancha_id'];
            $assignedDate = $slotAsignado['fecha'];
            $assignedHora = $slotAsignado['hora'];
            $assignedFechaHora = "$assignedDate $assignedHora:00";

            $horaIniRes = "$assignedHora:00";
            $hFinH = floor($slotAsignado['end_min'] / 60);
            $hFinM = $slotAsignado['end_min'] % 60;
            $horaFinRes = ($slotAsignado['end_min'] >= 1440) ? '24:00:00' : sprintf('%02d:%02d:00', $hFinH, $hFinM);

            // 1. Bloquear de inmediato el intervalo en la cancha para los siguientes partidos (Garantiza cero solapamiento)
            if (!isset($canchaBusy[$assignedDate])) $canchaBusy[$assignedDate] = [];
            if (!isset($canchaBusy[$assignedDate][$assignedCancha])) $canchaBusy[$assignedDate][$assignedCancha] = [];
            $canchaBusy[$assignedDate][$assignedCancha][] = [
                'start' => $slotAsignado['start_min'],
                'end' => $slotAsignado['end_min'],
                'tipo' => 'Liga'
            ];

            // 2. Registrar descanso para parejas y jugadores
            $restStart = $slotAsignado['ts_start'] - 5400;
            $restEnd = $slotAsignado['ts_end'] + 5400;
            if ($p1Id > 0) $parejaBusyTs[$p1Id][] = ['start' => $restStart, 'end' => $restEnd];
            if ($p2Id > 0) $parejaBusyTs[$p2Id][] = ['start' => $restStart, 'end' => $restEnd];
            foreach ($pJugadoresIds as $uId) {
                $jugadorBusyTs[$uId][] = ['start' => $restStart, 'end' => $restEnd];
            }

            // 3. Actualizar liga_partidos
            $stmt_up->bind_param("isi", $assignedCancha, $assignedFechaHora, $p['id']);
            $stmt_up->execute();

            $programadosCount++;
            $partidosProgramadosIds[] = (int)$p['id'];
            $parejaMatchesCount[$p1Id] = ($parejaMatchesCount[$p1Id] ?? 0) + 1;
            $parejaMatchesCount[$p2Id] = ($parejaMatchesCount[$p2Id] ?? 0) + 1;

            // 4. Crear reserva oficial en reservas_cancha
            $j1_id = (!empty($p['p1_j1_id']) && (int)$p['p1_j1_id'] > 0) ? (int)$p['p1_j1_id'] : null;
            $j2_id = (!empty($p['p1_j2_id']) && (int)$p['p1_j2_id'] > 0) ? (int)$p['p1_j2_id'] : null;
            $j3_id = (!empty($p['p2_j1_id']) && (int)$p['p2_j1_id'] > 0) ? (int)$p['p2_j1_id'] : null;
            $j4_id = (!empty($p['p2_j2_id']) && (int)$p['p2_j2_id'] > 0) ? (int)$p['p2_j2_id'] : null;

            $ext1 = !empty($p['p1_j1_u']) ? $p['p1_j1_u'] : (!empty($p['p1_j1_ext']) ? $p['p1_j1_ext'] : (!empty($p['p1_nom']) ? $p['p1_nom'] : 'P1-J1'));
            $ext2 = !empty($p['p1_j2_u']) ? $p['p1_j2_u'] : (!empty($p['p1_j2_ext']) ? $p['p1_j2_ext'] : 'P1-J2');
            $ext3 = !empty($p['p2_j1_u']) ? $p['p2_j1_u'] : (!empty($p['p2_j1_ext']) ? $p['p2_j1_ext'] : (!empty($p['p2_nom']) ? $p['p2_nom'] : 'P2-J1'));
            $ext4 = !empty($p['p2_j2_u']) ? $p['p2_j2_u'] : (!empty($p['p2_j2_ext']) ? $p['p2_j2_ext'] : 'P2-J2');

            $stmt_res->bind_param("iiiiisssssssi", $assignedCancha, $j1_id, $j2_id, $j3_id, $j4_id, $ext1, $ext2, $ext3, $ext4, $assignedDate, $horaIniRes, $horaFinRes, $p['id']);
            $stmt_res->execute();
        } else {
            // NO hubo cupo disponible en ningún día/horario: Dejar sin disponibilidad de horario (sin cancha y sin fecha)
            $partidosSinHorario[] = [
                'id' => (int)$p['id'],
                'parejas' => ($p['p1_nom'] ?: 'P1') . ' vs ' . ($p['p2_nom'] ?: 'P2'),
                'motivo' => 'Sin cupo libre en las canchas y días seleccionados'
            ];
        }

        $matchIdx++;
    }

    $ultimaFecha = date('Y-m-d', strtotime("+" . ($diasLoop - 1) . " days", $inicioTimestamp));
    $conn->query("UPDATE liga_jornadas SET fecha_limite = '$ultimaFecha' WHERE id IN ($j_ids_str)");

    // Enviar notificaciones por correo y push para los partidos asignados
    if (!empty($partidosProgramadosIds)) {
        try {
            require_once __DIR__ . '/notificaciones_liga.php';
            foreach ($partidosProgramadosIds as $progPid) {
                notificarPartidoLiga($conn, $progPid, 'programado');
            }
        } catch (Throwable $eNotif) {
            error_log("Error notificando partidos semanales: " . $eNotif->getMessage());
        }
    }

    $sinHorarioTotal = count($partidosSinHorario);
    $mensaje = "Se programaron $programadosCount de " . count($partidos) . " partidos para la jornada.";
    if ($sinHorarioTotal > 0) {
        $mensaje .= " $sinHorarioTotal partido(s) quedaron sin disponibilidad de horario por falta de cupos en las canchas o días seleccionados.";
    }

    echo json_encode([
        "success" => true,
        "mensaje" => $mensaje,
        "programados" => $programadosCount,
        "sin_horario" => $sinHorarioTotal,
        "total" => count($partidos),
        "partidos_sin_horario" => $partidosSinHorario
    ]);

} catch (Throwable $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
