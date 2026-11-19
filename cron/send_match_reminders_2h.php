<?php
/**
 * Cron Job: High-Precision 2-Hour & 1-Hour Pre-Match and Training Reminder Engine
 * PadelBlox Mobile App & Notification Engine
 * 
 * Runs every 10 minutes via cron:
 * curl -s https://padelblox.com/api/cron/send_match_reminders_2h.php > /dev/null 2>&1
 * 
 * High Precision Windows (centered around exact targets with ±10 min tolerance):
 * - Phase 2 Hours: 110 to 130 minutes before start time
 * - Phase 1 Hour:   50 to  70 minutes before start time
 * 
 * Scans upcoming:
 * 1. Friendly Matches / Court Bookings (reservas_cancha)
 * 2. League & Tournament Fixtures (liga_partidos)
 * 3. American Tournaments (torneos_americanos)
 * 4. Coach Training Sessions & Classes (reservas)
 * 5. Queued Reminders (recordatorios_programados)
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    header("Content-Type: application/json; charset=UTF-8");
}

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../notifications/fcm_sender.php";
require_once __DIR__ . "/../notifications/notificaciones_helper.php";

$nowSantiago = date('Y-m-d H:i:s');
$today = date('Y-m-d');
$currentTime = date('H:i:s');
$nowTs = time();

// Options & Parameters
$isForce = isset($_GET['force']) && ($_GET['force'] == '1' || $_GET['force'] === 'true');
$isDryRun = isset($_GET['dry_run']) && ($_GET['dry_run'] == '1' || $_GET['dry_run'] === 'true');
$filterUserId = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;

// Precise window definitions (minutes before event)
$window2hMin = isset($_GET['w2_min']) ? intval($_GET['w2_min']) : 110;
$window2hMax = isset($_GET['w2_max']) ? intval($_GET['w2_max']) : 130;
$window1hMin = isset($_GET['w1_min']) ? intval($_GET['w1_min']) : 50;
$window1hMax = isset($_GET['w1_max']) ? intval($_GET['w1_max']) : 70;

// Broad SQL filter limits to fetch candidates efficiently
$sqlQueryMinMinutes = 45;
$sqlQueryMaxMinutes = 135;

$summary = [
    "success" => true,
    "timestamp" => $nowSantiago,
    "mode" => $isDryRun ? "dry_run" : ($isForce ? "force_all_today" : "precise_cron_2h_1h"),
    "windows" => [
        "2h_exact_window" => ["$window2hMin min", "$window2hMax min"],
        "1h_exact_window" => ["$window1hMin min", "$window1hMax min"]
    ],
    "processed_events" => [
        "reservas_cancha" => 0,
        "liga_partidos" => 0,
        "torneos_americanos" => 0,
        "entrenamientos" => 0
    ],
    "notifications_sent" => 0,
    "notifications_sent_2h" => 0,
    "notifications_sent_1h" => 0,
    "notifications_skipped_duplicate" => 0,
    "details" => []
];

/**
 * Determine exact reminder stage based on remaining minutes.
 * Returns '2h', '1h', or null if event is not within precise windows.
 */
function getReminderStage($eventDateTimeStr, $nowTs, $w2Min, $w2Max, $w1Min, $w1Max, $isForce) {
    $eventTs = strtotime($eventDateTimeStr);
    if (!$eventTs) return null;
    $diffMinutes = round(($eventTs - $nowTs) / 60);

    if ($diffMinutes >= $w2Min && $diffMinutes <= $w2Max) {
        return '2h';
    }
    if ($diffMinutes >= $w1Min && $diffMinutes <= $w1Max) {
        return '1h';
    }

    if ($isForce) {
        return ($diffMinutes <= 80) ? '1h' : '2h';
    }

    return null;
}

/**
 * Helper to check if user has already been notified for a specific stage ('2h' or '1h')
 * Prevents 2h notification from blocking 1h notification, while avoiding duplicate dispatches.
 */
function hasBeenNotifiedStage($conn, $userId, $stage, $fechaRef, $identificadorEvento) {
    $userId = intval($userId);
    if ($userId <= 0) return false;

    try {
        $stmt = @$conn->prepare("
            SELECT id FROM notificaciones 
            WHERE user_id = ? 
              AND tipo LIKE CONCAT('%', ?, '%')
              AND (
                  fecha_referencia = ? 
                  OR (DATE(fecha) = CURDATE() AND mensaje LIKE ?)
                  OR (DATE(fecha) = CURDATE() AND fecha_referencia LIKE ?)
                  OR (DATE(fecha) = CURDATE() AND fecha_referencia = ?)
              )
            LIMIT 1
        ");
        if ($stmt) {
            $likePattern = "%" . $identificadorEvento . "%";
            $fechaPattern = substr($fechaRef, 0, 10) . "%";
            $stmt->bind_param('isssss', $userId, $stage, $fechaRef, $likePattern, $fechaPattern, $fechaRef);
            $stmt->execute();
            $res = $stmt->get_result();
            $exists = ($res && $res->num_rows > 0);
            $stmt->close();
            return $exists;
        }
    } catch (Throwable $e) {
        try {
            $stmtFallback = @$conn->prepare("
                SELECT id FROM notificaciones 
                WHERE user_id = ? 
                  AND tipo LIKE CONCAT('%', ?, '%')
                  AND DATE(fecha) = CURDATE() 
                  AND mensaje LIKE ?
                LIMIT 1
            ");
            if ($stmtFallback) {
                $likePattern = "%" . $identificadorEvento . "%";
                $stmtFallback->bind_param('iss', $userId, $stage, $likePattern);
                $stmtFallback->execute();
                $res = $stmtFallback->get_result();
                $exists = ($res && $res->num_rows > 0);
                $stmtFallback->close();
                return $exists;
            }
        } catch (Throwable $e2) {
            return false;
        }
    }
    return false;
}

// Backward-compatible alias
function hasBeenNotified2H($conn, $userId, $tipo, $fechaRef, $identificadorEvento) {
    $stage = (strpos($tipo, '1h') !== false) ? '1h' : '2h';
    return hasBeenNotifiedStage($conn, $userId, $stage, $fechaRef, $identificadorEvento);
}

try {
    // =========================================================================
    // 1. SCAN RESERVAS DE CANCHA (PARTIDOS AMISTOSOS / RESERVAS DE PISTA DIRECTAS)
    // =========================================================================
    $checkReservasCancha = $conn->query("SHOW TABLES LIKE 'reservas_cancha'");
    if ($checkReservasCancha && $checkReservasCancha->num_rows > 0) {
        $checkColTorneo = $conn->query("SHOW COLUMNS FROM reservas_cancha LIKE 'torneo_id'");
        $hasTorneoCol = ($checkColTorneo && $checkColTorneo->num_rows > 0);
        $checkColTipoRes = $conn->query("SHOW COLUMNS FROM reservas_cancha LIKE 'tipo_reserva'");
        $hasTipoResCol = ($checkColTipoRes && $checkColTipoRes->num_rows > 0);

        $sqlReservas = "
            SELECT r.id, r.fecha, r.hora_inicio, r.hora_fin, r.cancha_id,
                   r.usuario_id, r.jugador2_id, r.jugador3_id, r.jugador4_id,
                   c.nombre AS cancha_nombre,
                   cl.id AS club_id, cl.nombre AS club_nombre,
                   u1.nombre AS j1_nombre, u1.apellido AS j1_ape,
                   u2.nombre AS j2_nombre, u2.apellido AS j2_ape,
                   u3.nombre AS j3_nombre, u3.apellido AS j3_ape,
                   u4.nombre AS j4_nombre, u4.apellido AS j4_ape
            FROM reservas_cancha r
            JOIN canchas c ON r.cancha_id = c.id
            JOIN clubes cl ON c.club_id = cl.id
            LEFT JOIN usuarios u1 ON r.usuario_id = u1.id
            LEFT JOIN usuarios u2 ON r.jugador2_id = u2.id
            LEFT JOIN usuarios u3 ON r.jugador3_id = u3.id
            LEFT JOIN usuarios u4 ON r.jugador4_id = u4.id
            WHERE r.fecha BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 1 DAY)
              AND (r.estado IS NULL OR (
                  LOWER(TRIM(r.estado)) NOT IN ('cancelada', 'cancelado', 'anulada', 'anulado', 'rechazada', 'rechazado', 'eliminada', 'eliminado', 'bloqueado', 'bloqueada')
                  AND LOWER(TRIM(r.estado)) NOT LIKE '%cancel%'
              ))
              AND (r.liga_partido_id IS NULL OR r.liga_partido_id = 0)
              AND (r.reserva_entrenador_id IS NULL OR r.reserva_entrenador_id = 0)
        ";

        if ($hasTorneoCol) {
            $sqlReservas .= " AND (r.torneo_id IS NULL OR r.torneo_id = 0) ";
        }
        if ($hasTipoResCol) {
            $sqlReservas .= " AND (r.tipo_reserva IS NULL OR LOWER(TRIM(r.tipo_reserva)) NOT IN ('americano', 'entrenamiento')) ";
        }

        if (!$isForce) {
            $sqlReservas .= "
              AND CONCAT(r.fecha, ' ', r.hora_inicio) >= DATE_ADD(NOW(), INTERVAL $sqlQueryMinMinutes MINUTE)
              AND CONCAT(r.fecha, ' ', r.hora_inicio) <= DATE_ADD(NOW(), INTERVAL $sqlQueryMaxMinutes MINUTE)
            ";
        }

        $resR = $conn->query($sqlReservas);
        if ($resR) {
            while ($row = $resR->fetch_assoc()) {
                $summary["processed_events"]["reservas_cancha"]++;
                $reservaId = $row['id'];
                $horaInicio = substr($row['hora_inicio'], 0, 5);
                $canchaNombre = $row['cancha_nombre'] ?? 'Cancha Central';
                $clubNombre = $row['club_nombre'] ?? 'Club Pádel';
                $fechaRef = $row['fecha'] . ' ' . $row['hora_inicio'];

                $stage = getReminderStage($fechaRef, $nowTs, $window2hMin, $window2hMax, $window1hMin, $window1hMax, $isForce);
                if (!$stage) continue;

                // Gather all 4 players
                $playerIds = array_filter([
                    intval($row['usuario_id']),
                    intval($row['jugador2_id']),
                    intval($row['jugador3_id']),
                    intval($row['jugador4_id'])
                ], function($id) { return $id > 0; });

                $uniquePlayers = array_unique($playerIds);

                foreach ($uniquePlayers as $pId) {
                    if ($filterUserId && $pId !== $filterUserId) continue;

                    $tipoNotif = "recordatorio_{$stage}_amistoso";
                    $identificador = "reserva #$reservaId";

                    if (hasBeenNotifiedStage($conn, $pId, $stage, $fechaRef, $identificador)) {
                        $summary["notifications_skipped_duplicate"]++;
                        continue;
                    }

                    if ($stage === '2h') {
                        $titulo = "🎾 ¡Tu partido en $canchaNombre es en 2 horas!";
                        $mensaje = "Hoy a las $horaInicio hrs en $clubNombre. Prepárate con tiempo y activa tu Apple Watch al entrar a la pista.";
                    } else {
                        $titulo = "⚡ ¡Tu partido en $canchaNombre arranca en 1 hora!";
                        $mensaje = "Tu partido comienza a las $horaInicio hrs en $clubNombre. ¡Es momento de calentar y entrar a la pista!";
                    }

                    $extraPayload = [
                        'event_type' => 'reserva_cancha',
                        'reserva_id' => (string)$reservaId,
                        'hora_inicio' => $horaInicio,
                        'club' => $clubNombre,
                        'cancha' => $canchaNombre,
                        'reminder_stage' => $stage,
                        'route' => '/jugador-partidos'
                    ];

                    if (!$isDryRun) {
                        if ($stage === '2h') {
                            notifyMatchReminder2H($conn, $pId, $titulo, $mensaje, $tipoNotif, $fechaRef, $extraPayload);
                        } else {
                            notifyMatchReminder1H($conn, $pId, $titulo, $mensaje, $tipoNotif, $fechaRef, $extraPayload);
                        }
                        // Neutralize only corresponding stage in queue
                        @$conn->query("UPDATE recordatorios_programados SET enviado = 1, enviado_at = NOW() WHERE (reserva_id = $reservaId OR (user_id = $pId AND fecha_programada LIKE '" . substr($fechaRef, 0, 10) . "%')) AND tipo LIKE '%{$stage}%' AND enviado = 0");
                    }

                    $summary["notifications_sent"]++;
                    if ($stage === '2h') $summary["notifications_sent_2h"]++;
                    if ($stage === '1h') $summary["notifications_sent_1h"]++;

                    $summary["details"][] = [
                        "type" => "reserva_cancha",
                        "stage" => $stage,
                        "user_id" => $pId,
                        "event_id" => $reservaId,
                        "title" => $titulo,
                        "hora" => $horaInicio,
                        "status" => $isDryRun ? "simulated" : "sent"
                    ];
                }
            }
        }
    }

    // =========================================================================
    // 2. SCAN PARTIDOS DE LIGA Y TORNEO (liga_partidos)
    // =========================================================================
    $checkLiga = $conn->query("SHOW TABLES LIKE 'liga_partidos'");
    if ($checkLiga && $checkLiga->num_rows > 0) {
        $sqlLigas = "
            SELECT lp.id, 
                   DATE(lp.fecha_hora) AS fecha,
                   TIME(lp.fecha_hora) AS hora_inicio,
                   lp.fecha_hora,
                   lp.cancha_id,
                   par1.nombre_pareja AS p1_raw_nombre,
                   par1.nombre_externo1 AS p1_ext1,
                   par1.nombre_externo2 AS p1_ext2,
                   par1.jugador1_id AS p1_j1,
                   par1.jugador2_id AS p1_j2,
                   u11.nombre AS p1_j1_nom, COALESCE(u11.apellido, '') AS p1_j1_ape,
                   u12.nombre AS p1_j2_nom, COALESCE(u12.apellido, '') AS p1_j2_ape,
                   par2.nombre_pareja AS p2_raw_nombre,
                   par2.nombre_externo1 AS p2_ext1,
                   par2.nombre_externo2 AS p2_ext2,
                   par2.jugador1_id AS p2_j1,
                   par2.jugador2_id AS p2_j2,
                   u21.nombre AS p2_j1_nom, COALESCE(u21.apellido, '') AS p2_j1_ape,
                   u22.nombre AS p2_j2_nom, COALESCE(u22.apellido, '') AS p2_j2_ape,
                   COALESCE(l.nombre, 'Liga PadelBlox') AS liga_nombre,
                   COALESCE(cl.nombre, 'Club Pádel') AS club_nombre,
                   COALESCE(can.nombre, 'Cancha Oficial') AS cancha_nombre
            FROM liga_partidos lp
            JOIN liga_parejas par1 ON lp.pareja1_id = par1.id
            JOIN liga_parejas par2 ON lp.pareja2_id = par2.id
            LEFT JOIN usuarios u11 ON par1.jugador1_id = u11.id
            LEFT JOIN usuarios u12 ON par1.jugador2_id = u12.id
            LEFT JOIN usuarios u21 ON par2.jugador1_id = u21.id
            LEFT JOIN usuarios u22 ON par2.jugador2_id = u22.id
            LEFT JOIN liga_categorias cat ON par1.categoria_id = cat.id
            LEFT JOIN ligas l ON cat.liga_id = l.id
            LEFT JOIN clubes cl ON l.club_id = cl.id
            LEFT JOIN canchas can ON lp.cancha_id = can.id
            WHERE lp.fecha_hora IS NOT NULL
              AND lp.fecha_hora NOT LIKE '0000%'
              AND (lp.estado IS NULL OR (
                  LOWER(TRIM(lp.estado)) IN ('pendiente', 'programado')
                  AND LOWER(TRIM(lp.estado)) NOT IN ('cancelado', 'cancelada', 'suspendido', 'jugado', 'walkover')
                  AND LOWER(TRIM(lp.estado)) NOT LIKE '%cancel%'
              ))
              AND (par1.estado IS NULL OR (LOWER(TRIM(par1.estado)) != 'cancelada' AND LOWER(TRIM(par1.estado)) NOT LIKE '%cancel%'))
              AND (par2.estado IS NULL OR (LOWER(TRIM(par2.estado)) != 'cancelada' AND LOWER(TRIM(par2.estado)) NOT LIKE '%cancel%'))
              AND NOT EXISTS (
                  SELECT 1 FROM reservas_cancha rc_sub 
                  WHERE rc_sub.liga_partido_id = lp.id 
                    AND (LOWER(TRIM(rc_sub.estado)) IN ('cancelada', 'cancelado', 'anulada') OR LOWER(TRIM(rc_sub.estado)) LIKE '%cancel%')
              )
        ";

        if (!$isForce) {
            $sqlLigas .= "
              AND lp.fecha_hora >= DATE_ADD(NOW(), INTERVAL $sqlQueryMinMinutes MINUTE)
              AND lp.fecha_hora <= DATE_ADD(NOW(), INTERVAL $sqlQueryMaxMinutes MINUTE)
            ";
        } else {
            $sqlLigas .= "
              AND DATE(lp.fecha_hora) = CURDATE()
            ";
        }

        $resL = $conn->query($sqlLigas);
        if ($resL) {
            while ($lp = $resL->fetch_assoc()) {
                $summary["processed_events"]["liga_partidos"]++;
                $lpId = $lp['id'];
                $horaInicio = !empty($lp['hora_inicio']) ? substr($lp['hora_inicio'], 0, 5) : '20:00';
                $fechaRef = $lp['fecha_hora'];

                $stage = getReminderStage($fechaRef, $nowTs, $window2hMin, $window2hMax, $window1hMin, $window1hMax, $isForce);
                if (!$stage) continue;

                // Format dupla names cleanly
                $p1Name = !empty($lp['p1_raw_nombre']) ? $lp['p1_raw_nombre'] : trim(($lp['p1_j1_nom'] ?? '') . ' ' . ($lp['p1_j1_ape'] ?? ''));
                if (empty($p1Name)) $p1Name = !empty($lp['p1_ext1']) ? $lp['p1_ext1'] : 'Dupla 1';

                $p2Name = !empty($lp['p2_raw_nombre']) ? $lp['p2_raw_nombre'] : trim(($lp['p2_j1_nom'] ?? '') . ' ' . ($lp['p2_j1_ape'] ?? ''));
                if (empty($p2Name)) $p2Name = !empty($lp['p2_ext1']) ? $lp['p2_ext1'] : 'Dupla 2';

                $canchaNombre = $lp['cancha_nombre'] ?? 'Cancha Oficial';
                $clubNombre = $lp['club_nombre'] ?? 'Liga PadelBlox';

                $players = array_filter([
                    intval($lp['p1_j1']),
                    intval($lp['p1_j2']),
                    intval($lp['p2_j1']),
                    intval($lp['p2_j2'])
                ], function($id) { return $id > 0; });

                $uniqueLigaPlayers = array_unique($players);

                foreach ($uniqueLigaPlayers as $pId) {
                    if ($filterUserId && $pId !== $filterUserId) continue;

                    $tipoNotif = "recordatorio_{$stage}_liga";
                    $identificador = "liga_match #$lpId";

                    if (hasBeenNotifiedStage($conn, $pId, $stage, $fechaRef, $identificador)) {
                        $summary["notifications_skipped_duplicate"]++;
                        continue;
                    }

                    if ($stage === '2h') {
                        $titulo = "🏆 ¡Partido de Liga en 2 horas!";
                        $mensaje = "Tu partido $p1Name vs $p2Name arranca a las $horaInicio hrs en $canchaNombre ($clubNombre). ¡A darlo todo en la pista!";
                    } else {
                        $titulo = "⚡ ¡Partido de Liga en 1 hora!";
                        $mensaje = "Tu encuentro $p1Name vs $p2Name comienza a las $horaInicio hrs en $canchaNombre ($clubNombre). ¡A la pista!";
                    }

                    $extraPayload = [
                        'event_type' => 'liga_partido',
                        'liga_partido_id' => (string)$lpId,
                        'hora_inicio' => $horaInicio,
                        'pareja1' => $p1Name,
                        'pareja2' => $p2Name,
                        'reminder_stage' => $stage,
                        'route' => '/jugador-partidos'
                    ];

                    if (!$isDryRun) {
                        if ($stage === '2h') {
                            notifyMatchReminder2H($conn, $pId, $titulo, $mensaje, $tipoNotif, $fechaRef, $extraPayload);
                        } else {
                            notifyMatchReminder1H($conn, $pId, $titulo, $mensaje, $tipoNotif, $fechaRef, $extraPayload);
                        }
                        @$conn->query("UPDATE recordatorios_programados SET enviado = 1, enviado_at = NOW() WHERE (user_id = $pId AND fecha_programada LIKE '" . substr($fechaRef, 0, 10) . "%') AND tipo LIKE '%{$stage}%' AND enviado = 0");
                    }

                    $summary["notifications_sent"]++;
                    if ($stage === '2h') $summary["notifications_sent_2h"]++;
                    if ($stage === '1h') $summary["notifications_sent_1h"]++;

                    $summary["details"][] = [
                        "type" => "liga_partido",
                        "stage" => $stage,
                        "user_id" => $pId,
                        "event_id" => $lpId,
                        "title" => $titulo,
                        "hora" => $horaInicio,
                        "status" => $isDryRun ? "simulated" : "sent"
                    ];
                }
            }
        }
    }

    // =========================================================================
    // 3. SCAN TORNEOS AMERICANOS (torneos_americanos)
    // =========================================================================
    $checkTorneoAm = $conn->query("SHOW TABLES LIKE 'torneos_americanos'");
    if ($checkTorneoAm && $checkTorneoAm->num_rows > 0) {
        $sqlTorneos = "
            SELECT t.id, t.fecha, t.hora_inicio, t.nombre AS torneo_nombre,
                   COALESCE(cl.nombre, 'Club Pádel') AS club_nombre,
                   tp.jugador_id AS j1, tp.jugador2_id AS j2
            FROM torneos_americanos t
            JOIN torneo_participantes tp ON tp.torneo_id = t.id
            LEFT JOIN clubes cl ON t.club_id = cl.id
            WHERE t.fecha = CURDATE()
              AND (t.estado IS NULL OR (
                  LOWER(TRIM(t.estado)) NOT IN ('cancelado', 'cancelada', 'finalizado', 'cerrado', 'anulado', 'anulada')
                  AND LOWER(TRIM(t.estado)) NOT LIKE '%cancel%'
              ))
        ";

        if (!$isForce) {
            $sqlTorneos .= "
              AND CONCAT(t.fecha, ' ', t.hora_inicio) >= DATE_ADD(NOW(), INTERVAL $sqlQueryMinMinutes MINUTE)
              AND CONCAT(t.fecha, ' ', t.hora_inicio) <= DATE_ADD(NOW(), INTERVAL $sqlQueryMaxMinutes MINUTE)
            ";
        }

        $resT = $conn->query($sqlTorneos);
        if ($resT) {
            while ($tRow = $resT->fetch_assoc()) {
                $summary["processed_events"]["torneos_americanos"]++;
                $tId = $tRow['id'];
                $horaInicio = substr($tRow['hora_inicio'], 0, 5);
                $torneoNombre = $tRow['torneo_nombre'] ?? 'Torneo Americano';
                $clubNombre = $tRow['club_nombre'] ?? 'Club Pádel';
                $fechaRef = $tRow['fecha'] . ' ' . $tRow['hora_inicio'];

                $stage = getReminderStage($fechaRef, $nowTs, $window2hMin, $window2hMax, $window1hMin, $window1hMax, $isForce);
                if (!$stage) continue;

                $players = array_filter([
                    intval($tRow['j1']),
                    intval($tRow['j2'])
                ], function($id) { return $id > 0; });

                foreach (array_unique($players) as $pId) {
                    if ($filterUserId && $pId !== $filterUserId) continue;

                    $tipoNotif = "recordatorio_{$stage}_torneo";
                    $identificador = "torneo #$tId";

                    if (hasBeenNotifiedStage($conn, $pId, $stage, $fechaRef, $identificador)) {
                        $summary["notifications_skipped_duplicate"]++;
                        continue;
                    }

                    if ($stage === '2h') {
                        $titulo = "🏅 ¡Tu Torneo $torneoNombre inicia en 2 horas!";
                        $mensaje = "El torneo arranca hoy a las $horaInicio hrs en $clubNombre. ¡Mucho éxito en tus partidos!";
                    } else {
                        $titulo = "⚡ ¡Tu Torneo $torneoNombre arranca en 1 hora!";
                        $mensaje = "La jornada comienza a las $horaInicio hrs en $clubNombre. ¡No olvides llegar temprano a la pista!";
                    }

                    $extraPayload = [
                        'event_type' => 'torneo_americano',
                        'torneo_id' => (string)$tId,
                        'hora_inicio' => $horaInicio,
                        'torneo' => $torneoNombre,
                        'club' => $clubNombre,
                        'reminder_stage' => $stage,
                        'route' => '/jugador-campeonatos'
                    ];

                    if (!$isDryRun) {
                        if ($stage === '2h') {
                            notifyMatchReminder2H($conn, $pId, $titulo, $mensaje, $tipoNotif, $fechaRef, $extraPayload);
                        } else {
                            notifyMatchReminder1H($conn, $pId, $titulo, $mensaje, $tipoNotif, $fechaRef, $extraPayload);
                        }
                    }

                    $summary["notifications_sent"]++;
                    if ($stage === '2h') $summary["notifications_sent_2h"]++;
                    if ($stage === '1h') $summary["notifications_sent_1h"]++;

                    $summary["details"][] = [
                        "type" => "torneo_americano",
                        "stage" => $stage,
                        "user_id" => $pId,
                        "event_id" => $tId,
                        "title" => $titulo,
                        "hora" => $horaInicio,
                        "status" => $isDryRun ? "simulated" : "sent"
                    ];
                }
            }
        }
    }

    // =========================================================================
    // 4. SCAN ENTRENAMIENTOS / CLASES DE PÁDEL (reservas)
    // =========================================================================
    $checkReservas = $conn->query("SHOW TABLES LIKE 'reservas'");
    if ($checkReservas && $checkReservas->num_rows > 0) {
        $sqlEntrenamientos = "
            SELECT r.id, r.fecha, r.hora_inicio, r.hora_fin, r.entrenador_id,
                   p.nombre AS pack_nombre,
                   cl.nombre AS club_nombre,
                   ue.nombre AS coach_nombre, ue.apellido AS coach_ape,
                   GROUP_CONCAT(COALESCE(rj.jugador_id, '') SEPARATOR ',') AS alumnos_ids,
                   GROUP_CONCAT(COALESCE(uj.nombre, '') SEPARATOR ', ') AS alumnos_nombres
            FROM reservas r
            LEFT JOIN packs p ON r.pack_id = p.id
            LEFT JOIN clubes cl ON r.club_id = cl.id
            LEFT JOIN usuarios ue ON r.entrenador_id = ue.id
            LEFT JOIN reserva_jugadores rj ON rj.reserva_id = r.id
            LEFT JOIN usuarios uj ON rj.jugador_id = uj.id
            WHERE r.fecha = CURDATE()
              AND LOWER(TRIM(r.estado)) = 'reservado'
              AND LOWER(TRIM(r.estado)) NOT IN ('cancelada', 'cancelado', 'bloqueado', 'anulado')
              AND r.entrenador_id > 0
              AND NOT EXISTS (
                  SELECT 1 FROM reservas_cancha rc_e 
                  WHERE rc_e.reserva_entrenador_id = r.id 
                    AND (LOWER(TRIM(rc_e.estado)) IN ('cancelada', 'cancelado', 'anulada') OR LOWER(TRIM(rc_e.estado)) LIKE '%cancel%')
              )
        ";

        if (!$isForce) {
            $sqlEntrenamientos .= "
              AND CONCAT(r.fecha, ' ', r.hora_inicio) >= DATE_ADD(NOW(), INTERVAL $sqlQueryMinMinutes MINUTE)
              AND CONCAT(r.fecha, ' ', r.hora_inicio) <= DATE_ADD(NOW(), INTERVAL $sqlQueryMaxMinutes MINUTE)
            ";
        }

        $sqlEntrenamientos .= " GROUP BY r.id";

        $resE = $conn->query($sqlEntrenamientos);
        if ($resE) {
            while ($re = $resE->fetch_assoc()) {
                $summary["processed_events"]["entrenamientos"]++;
                $reservaId = $re['id'];
                $horaInicio = substr($re['hora_inicio'], 0, 5);
                $packNombre = $re['pack_nombre'] ?? 'Clase de Pádel';
                $clubNombre = $re['club_nombre'] ?? 'Club Pádel';
                $coachNombre = trim(($re['coach_nombre'] ?? 'Tu Profesor') . ' ' . ($re['coach_ape'] ?? ''));
                $alumnosNombres = !empty($re['alumnos_nombres']) ? $re['alumnos_nombres'] : 'tus alumnos';
                $coachId = intval($re['entrenador_id']);
                $fechaRef = $re['fecha'] . ' ' . $re['hora_inicio'];

                $stage = getReminderStage($fechaRef, $nowTs, $window2hMin, $window2hMax, $window1hMin, $window1hMax, $isForce);
                if (!$stage) continue;

                // 4.A NOTIFY STUDENTS (ALUMNOS)
                $rawAlumnoIds = !empty($re['alumnos_ids']) ? explode(',', $re['alumnos_ids']) : [];
                $alumnoIds = array_filter(array_map('intval', $rawAlumnoIds), function($id) { return $id > 0; });
                $uniqueAlumnos = array_unique($alumnoIds);

                foreach ($uniqueAlumnos as $aId) {
                    if ($filterUserId && $aId !== $filterUserId) continue;

                    $tipoNotif = "recordatorio_{$stage}_clase";
                    $identificador = "clase #$reservaId";

                    if (hasBeenNotifiedStage($conn, $aId, $stage, $fechaRef, $identificador)) {
                        $summary["notifications_skipped_duplicate"]++;
                        continue;
                    }

                    if ($stage === '2h') {
                        $titulo = "🏋️‍♂️ ¡Tu clase de Pádel es en 2 horas!";
                        $mensaje = "Tu entrenamiento de $packNombre con $coachNombre comienza a las $horaInicio hrs en $clubNombre. ¡A darlo todo! (Clase #$reservaId)";
                    } else {
                        $titulo = "⚡ ¡Tu clase de Pádel es en 1 hora!";
                        $mensaje = "Tu sesión de $packNombre con $coachNombre arranca a las $horaInicio hrs en $clubNombre. ¡Prepárate!";
                    }

                    $extraPayload = [
                        'event_type' => 'clase_padel',
                        'reserva_id' => (string)$reservaId,
                        'pack' => $packNombre,
                        'coach' => $coachNombre,
                        'hora' => $horaInicio,
                        'reminder_stage' => $stage,
                        'route' => '/jugador-reservas'
                    ];

                    if (!$isDryRun) {
                        if ($stage === '2h') {
                            notifyMatchReminder2H($conn, $aId, $titulo, $mensaje, $tipoNotif, $fechaRef, $extraPayload);
                        } else {
                            notifyMatchReminder1H($conn, $aId, $titulo, $mensaje, $tipoNotif, $fechaRef, $extraPayload);
                        }
                    }

                    $summary["notifications_sent"]++;
                    if ($stage === '2h') $summary["notifications_sent_2h"]++;
                    if ($stage === '1h') $summary["notifications_sent_1h"]++;

                    $summary["details"][] = [
                        "type" => "clase_alumno",
                        "stage" => $stage,
                        "user_id" => $aId,
                        "event_id" => $reservaId,
                        "title" => $titulo,
                        "hora" => $horaInicio,
                        "status" => $isDryRun ? "simulated" : "sent"
                    ];
                }

                // 4.B NOTIFY COACH (PROFESOR) - Solo si hay alumnos confirmados en la clase
                if (!empty($uniqueAlumnos) && $coachId > 0 && (!$filterUserId || $coachId === $filterUserId)) {
                    $tipoCoach = "recordatorio_{$stage}_coach";
                    $identificadorCoach = "clase_coach #$reservaId";

                    if (!hasBeenNotifiedStage($conn, $coachId, $stage, $fechaRef, $identificadorCoach)) {
                        if ($stage === '2h') {
                            $tituloCoach = "📋 ¡Clase programada en 2 horas!";
                            $mensajeCoach = "Tienes sesión de $packNombre a las $horaInicio hrs en $clubNombre con $alumnosNombres. (Clase #$reservaId)";
                        } else {
                            $tituloCoach = "⚡ ¡Clase en 1 hora!";
                            $mensajeCoach = "Tu sesión de $packNombre comienza a las $horaInicio hrs en $clubNombre con $alumnosNombres.";
                        }

                        $extraPayloadCoach = [
                            'event_type' => 'clase_coach',
                            'reserva_id' => (string)$reservaId,
                            'pack' => $packNombre,
                            'alumnos' => $alumnosNombres,
                            'hora' => $horaInicio,
                            'reminder_stage' => $stage,
                            'route' => '/entrenador-entrenamientos'
                        ];

                        if (!$isDryRun) {
                            if ($stage === '2h') {
                                notifyMatchReminder2H($conn, $coachId, $tituloCoach, $mensajeCoach, $tipoCoach, $fechaRef, $extraPayloadCoach);
                            } else {
                                notifyMatchReminder1H($conn, $coachId, $tituloCoach, $mensajeCoach, $tipoCoach, $fechaRef, $extraPayloadCoach);
                            }
                        }

                        $summary["notifications_sent"]++;
                        if ($stage === '2h') $summary["notifications_sent_2h"]++;
                        if ($stage === '1h') $summary["notifications_sent_1h"]++;

                        $summary["details"][] = [
                            "type" => "clase_coach",
                            "stage" => $stage,
                            "user_id" => $coachId,
                            "event_id" => $reservaId,
                            "title" => $tituloCoach,
                            "hora" => $horaInicio,
                            "status" => $isDryRun ? "simulated" : "sent"
                        ];
                    } else {
                        $summary["notifications_skipped_duplicate"]++;
                    }
                }
            }
        }
    }

    // =========================================================================
    // 5. SCAN RECORDATORIOS PROGRAMADOS EN COLA (recordatorios_programados)
    // =========================================================================
    $checkRecProg = $conn->query("SHOW TABLES LIKE 'recordatorios_programados'");
    if ($checkRecProg && $checkRecProg->num_rows > 0) {
        // Expirar recordatorios con más de 3 horas de retraso para evitar spam de recordatorios obsoletos
        @$conn->query("UPDATE recordatorios_programados SET enviado = 2, enviado_at = NOW() WHERE enviado = 0 AND fecha_programada < DATE_SUB(NOW(), INTERVAL 3 HOUR)");

        $checkColRes = $conn->query("SHOW COLUMNS FROM recordatorios_programados LIKE 'reserva_id'");
        $hasReservaIdCol = ($checkColRes && $checkColRes->num_rows > 0);

        $sqlProg = "SELECT id, user_id, titulo, mensaje, tipo, fecha_programada " . ($hasReservaIdCol ? ", reserva_id" : "") . "
                    FROM recordatorios_programados 
                    WHERE enviado = 0 
                      AND fecha_programada <= NOW()
                      AND fecha_programada >= DATE_SUB(NOW(), INTERVAL 3 HOUR)
                    LIMIT 100";
        $resProg = $conn->query($sqlProg);
        if ($resProg) {
            while ($rProg = $resProg->fetch_assoc()) {
                $pId = intval($rProg['user_id']);
                $recId = intval($rProg['id']);
                $rResId = isset($rProg['reserva_id']) ? intval($rProg['reserva_id']) : 0;
                $fProg = $rProg['fecha_programada'] ?? date('Y-m-d H:i:s');
                $tipoP = $rProg['tipo'] ?? 'recordatorio';
                if ($filterUserId && $pId !== $filterUserId) continue;

                // 5.A Verificar si el evento vinculado ha sido cancelado (en reservas_cancha o reservas)
                if ($rResId > 0) {
                    $isCancelled = false;

                    // Revisar reservas_cancha (canchas / partidos amistosos)
                    $stCheckCancha = $conn->prepare("SELECT estado FROM reservas_cancha WHERE id = ?");
                    if ($stCheckCancha) {
                        $stCheckCancha->bind_param("i", $rResId);
                        $stCheckCancha->execute();
                        $resCheckC = $stCheckCancha->get_result()->fetch_assoc();
                        $stCheckCancha->close();
                        if ($resCheckC) {
                            $stC = strtolower(trim($resCheckC['estado'] ?? ''));
                            if (in_array($stC, ['cancelada', 'cancelado', 'anulada', 'anulado', 'rechazada', 'eliminada', 'bloqueado', 'bloqueada']) || strpos($stC, 'cancel') !== false) {
                                $isCancelled = true;
                            }
                        }
                    }

                    // Revisar reservas (clases de entrenador)
                    if (!$isCancelled) {
                        $stCheckVal = $conn->prepare("SELECT estado FROM reservas WHERE id = ?");
                        if ($stCheckVal) {
                            $stCheckVal->bind_param("i", $rResId);
                            $stCheckVal->execute();
                            $resCheckVal = $stCheckVal->get_result()->fetch_assoc();
                            $stCheckVal->close();
                            if ($resCheckVal) {
                                $stR = strtolower(trim($resCheckVal['estado'] ?? ''));
                                if (in_array($stR, ['cancelada', 'cancelado', 'bloqueado', 'anulado']) || strpos($stR, 'cancel') !== false) {
                                    $isCancelled = true;
                                }
                            }
                        }
                    }

                    if ($isCancelled) {
                        $conn->query("UPDATE recordatorios_programados SET enviado = 2, enviado_at = NOW() WHERE id = $recId");
                        continue;
                    }
                }

                // 5.B Control estricto de duplicados por etapa
                $stage = (strpos($tipoP, '1h') !== false) ? '1h' : '2h';
                $identificador = ($rResId > 0) ? "#$rResId" : $rProg['titulo'];

                if (hasBeenNotifiedStage($conn, $pId, $stage, $fProg, $identificador)) {
                    $conn->query("UPDATE recordatorios_programados SET enviado = 1, enviado_at = NOW() WHERE id = $recId");
                    $summary["notifications_skipped_duplicate"]++;
                    continue;
                }

                if (!$isDryRun) {
                    if (notifyUser($conn, $pId, $rProg['titulo'], $rProg['mensaje'], $tipoP, $fProg)) {
                        $conn->query("UPDATE recordatorios_programados SET enviado = 1, enviado_at = NOW() WHERE id = $recId");
                    }
                }
                $summary["notifications_sent"]++;
                if ($stage === '2h') $summary["notifications_sent_2h"]++;
                if ($stage === '1h') $summary["notifications_sent_1h"]++;

                $summary["details"][] = [
                    "type" => "recordatorio_programado",
                    "stage" => $stage,
                    "user_id" => $pId,
                    "event_id" => $recId,
                    "title" => $rProg['titulo'],
                    "status" => $isDryRun ? "simulated" : "sent"
                ];
            }
        }
    }

    // Response Output
    if ($isCli) {
        echo "=== [PadelBlox Precision 2H & 1H Match Reminder Engine] ===\n";
        echo "Timestamp: {$summary['timestamp']}\n";
        echo "Mode: {$summary['mode']}\n";
        echo "Window 2H: {$summary['windows']['2h_exact_window'][0]} - {$summary['windows']['2h_exact_window'][1]}\n";
        echo "Window 1H: {$summary['windows']['1h_exact_window'][0]} - {$summary['windows']['1h_exact_window'][1]}\n";
        echo "Partidos Amistosos: {$summary['processed_events']['reservas_cancha']}\n";
        echo "Partidos Liga: {$summary['processed_events']['liga_partidos']}\n";
        echo "Torneos Americanos: {$summary['processed_events']['torneos_americanos']}\n";
        echo "Entrenamientos: {$summary['processed_events']['entrenamientos']}\n";
        echo "Total Enviadas: {$summary['notifications_sent']} (2H: {$summary['notifications_sent_2h']} | 1H: {$summary['notifications_sent_1h']})\n";
        echo "Duplicados Omitidos: {$summary['notifications_skipped_duplicate']}\n";
        echo "===========================================================\n";
    } else {
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

} catch (Throwable $e) {
    http_response_code(500);
    $errRes = [
        "success" => false,
        "error" => $e->getMessage(),
        "trace" => $e->getTraceAsString()
    ];
    if ($isCli) {
        echo "ERROR: " . $e->getMessage() . "\n";
    } else {
        echo json_encode($errRes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
?>