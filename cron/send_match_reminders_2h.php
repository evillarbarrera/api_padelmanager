<?php
/**
 * Cron Job: 2-Hour Pre-Match & Training Reminder System
 * PadelBlox Mobile App & Notification Engine
 * 
 * Scans upcoming:
 * 1. Friendly Matches / Court Bookings (reservas_cancha)
 * 2. League & Tournament Fixtures (liga_partidos)
 * 3. Coach Training Sessions & Classes (reservas)
 * 
 * When an event is ~2 hours away (105 to 135 minutes), it sends:
 * - Native Push Notifications via FCM (APNs/Android)
 * - In-App Notification Center records
 * - Smartwatch deep linking payload
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

// Options & Parameters
$isForce = isset($_GET['force']) && ($_GET['force'] == '1' || $_GET['force'] === 'true');
$isDryRun = isset($_GET['dry_run']) && ($_GET['dry_run'] == '1' || $_GET['dry_run'] === 'true');
$filterUserId = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;
$windowMinutesMin = isset($_GET['win_min']) ? intval($_GET['win_min']) : 105; // 1h 45m
$windowMinutesMax = isset($_GET['win_max']) ? intval($_GET['win_max']) : 135; // 2h 15m

$summary = [
    "success" => true,
    "timestamp" => $nowSantiago,
    "mode" => $isDryRun ? "dry_run" : ($isForce ? "force_all_today" : "standard_cron_2h"),
    "window_minutes" => [$windowMinutesMin, $windowMinutesMax],
    "processed_events" => [
        "reservas_cancha" => 0,
        "liga_partidos" => 0,
        "torneos_americanos" => 0,
        "entrenamientos" => 0
    ],
    "notifications_sent" => 0,
    "notifications_skipped_duplicate" => 0,
    "details" => []
];

// Helper to check if already notified with defensive fallbacks
function hasBeenNotified2H($conn, $userId, $tipo, $fechaRef, $identificadorEvento) {
    $userId = intval($userId);
    if ($userId <= 0) return false;

    // Check with fecha_referencia
    try {
        $stmt = @$conn->prepare("
            SELECT id FROM notificaciones 
            WHERE user_id = ? 
              AND tipo = ? 
              AND (fecha_referencia = ? OR (DATE(fecha) = CURDATE() AND mensaje LIKE ?))
            LIMIT 1
        ");
        if ($stmt) {
            $likePattern = "%" . $identificadorEvento . "%";
            $stmt->bind_param('isss', $userId, $tipo, $fechaRef, $likePattern);
            $stmt->execute();
            $res = $stmt->get_result();
            $exists = ($res && $res->num_rows > 0);
            $stmt->close();
            return $exists;
        }
    } catch (Throwable $e) {
        // Fallback without fecha_referencia column
        try {
            $stmtFallback = @$conn->prepare("
                SELECT id FROM notificaciones 
                WHERE user_id = ? 
                  AND tipo = ? 
                  AND DATE(fecha) = CURDATE() 
                  AND mensaje LIKE ?
                LIMIT 1
            ");
            if ($stmtFallback) {
                $likePattern = "%" . $identificadorEvento . "%";
                $stmtFallback->bind_param('iss', $userId, $tipo, $likePattern);
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

try {
    // =========================================================================
    // 1. SCAN RESERVAS DE CANCHA (PARTIDOS AMISTOSOS / RESERVAS DE PISTA)
    // =========================================================================
    $checkReservasCancha = $conn->query("SHOW TABLES LIKE 'reservas_cancha'");
    if ($checkReservasCancha && $checkReservasCancha->num_rows > 0) {
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
            WHERE r.fecha = CURDATE()
              AND (r.estado IS NULL OR r.estado NOT IN ('cancelada', 'cancelado', 'Cancelada', 'Cancelado'))
        ";

        if (!$isForce) {
            $sqlReservas .= "
              AND CONCAT(r.fecha, ' ', r.hora_inicio) >= DATE_ADD(NOW(), INTERVAL $windowMinutesMin MINUTE)
              AND CONCAT(r.fecha, ' ', r.hora_inicio) <= DATE_ADD(NOW(), INTERVAL $windowMinutesMax MINUTE)
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

                    $tipoNotif = 'recordatorio_2h_amistoso';
                    $identificador = "reserva #$reservaId";

                    if (hasBeenNotified2H($conn, $pId, $tipoNotif, $fechaRef, $identificador)) {
                        $summary["notifications_skipped_duplicate"]++;
                        continue;
                    }

                    $titulo = "🎾 ¡Tu partido en $canchaNombre es en 2 horas!";
                    $mensaje = "Hoy a las $horaInicio hrs en $clubNombre. Prepárate con tiempo y activa tu Apple Watch al entrar a la pista.";
                    
                    $extraPayload = [
                        'event_type' => 'reserva_cancha',
                        'reserva_id' => (string)$reservaId,
                        'hora_inicio' => $horaInicio,
                        'club' => $clubNombre,
                        'cancha' => $canchaNombre,
                        'route' => '/jugador-partidos'
                    ];

                    if (!$isDryRun) {
                        notifyMatchReminder2H($conn, $pId, $titulo, $mensaje, $tipoNotif, $fechaRef, $extraPayload);
                    }

                    $summary["notifications_sent"]++;
                    $summary["details"][] = [
                        "type" => "reserva_cancha",
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
              AND (lp.estado IS NULL OR lp.estado IN ('Pendiente', 'Programado'))
        ";

        if (!$isForce) {
            $sqlLigas .= "
              AND lp.fecha_hora >= DATE_ADD(NOW(), INTERVAL $windowMinutesMin MINUTE)
              AND lp.fecha_hora <= DATE_ADD(NOW(), INTERVAL $windowMinutesMax MINUTE)
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
                
                // Format dupla names cleanly
                $p1Name = !empty($lp['p1_raw_nombre']) ? $lp['p1_raw_nombre'] : trim(($lp['p1_j1_nom'] ?? '') . ' ' . ($lp['p1_j1_ape'] ?? ''));
                if (empty($p1Name)) $p1Name = !empty($lp['p1_ext1']) ? $lp['p1_ext1'] : 'Dupla 1';

                $p2Name = !empty($lp['p2_raw_nombre']) ? $lp['p2_raw_nombre'] : trim(($lp['p2_j1_nom'] ?? '') . ' ' . ($lp['p2_j1_ape'] ?? ''));
                if (empty($p2Name)) $p2Name = !empty($lp['p2_ext1']) ? $lp['p2_ext1'] : 'Dupla 2';

                $canchaNombre = $lp['cancha_nombre'] ?? 'Cancha Oficial';
                $clubNombre = $lp['club_nombre'] ?? 'Liga PadelBlox';
                $fechaRef = $lp['fecha_hora'];

                $players = array_filter([
                    intval($lp['p1_j1']),
                    intval($lp['p1_j2']),
                    intval($lp['p2_j1']),
                    intval($lp['p2_j2'])
                ], function($id) { return $id > 0; });

                $uniqueLigaPlayers = array_unique($players);

                foreach ($uniqueLigaPlayers as $pId) {
                    if ($filterUserId && $pId !== $filterUserId) continue;

                    $tipoNotif = 'recordatorio_2h_liga';
                    $identificador = "liga_match #$lpId";

                    if (hasBeenNotified2H($conn, $pId, $tipoNotif, $fechaRef, $identificador)) {
                        $summary["notifications_skipped_duplicate"]++;
                        continue;
                    }

                    $titulo = "🏆 ¡Partido de Liga en 2 horas!";
                    $mensaje = "Tu partido $p1Name vs $p2Name arranca a las $horaInicio hrs en $canchaNombre ($clubNombre). ¡A darlo todo en la pista!";

                    $extraPayload = [
                        'event_type' => 'liga_partido',
                        'liga_partido_id' => (string)$lpId,
                        'hora_inicio' => $horaInicio,
                        'pareja1' => $p1Name,
                        'pareja2' => $p2Name,
                        'route' => '/jugador-partidos'
                    ];

                    if (!$isDryRun) {
                        notifyMatchReminder2H($conn, $pId, $titulo, $mensaje, $tipoNotif, $fechaRef, $extraPayload);
                    }

                    $summary["notifications_sent"]++;
                    $summary["details"][] = [
                        "type" => "liga_partido",
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
              AND (t.estado IS NULL OR t.estado NOT IN ('Cancelado', 'Finalizado', 'Cerrado'))
        ";

        if (!$isForce) {
            $sqlTorneos .= "
              AND CONCAT(t.fecha, ' ', t.hora_inicio) >= DATE_ADD(NOW(), INTERVAL $windowMinutesMin MINUTE)
              AND CONCAT(t.fecha, ' ', t.hora_inicio) <= DATE_ADD(NOW(), INTERVAL $windowMinutesMax MINUTE)
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

                $players = array_filter([
                    intval($tRow['j1']),
                    intval($tRow['j2'])
                ], function($id) { return $id > 0; });

                foreach (array_unique($players) as $pId) {
                    if ($filterUserId && $pId !== $filterUserId) continue;

                    $tipoNotif = 'recordatorio_2h_torneo';
                    $identificador = "torneo #$tId";

                    if (hasBeenNotified2H($conn, $pId, $tipoNotif, $fechaRef, $identificador)) {
                        $summary["notifications_skipped_duplicate"]++;
                        continue;
                    }

                    $titulo = "🏅 ¡Tu Torneo $torneoNombre inicia en 2 horas!";
                    $mensaje = "El torneo arranca hoy a las $horaInicio hrs en $clubNombre. ¡Mucho éxito en tus partidos!";

                    $extraPayload = [
                        'event_type' => 'torneo_americano',
                        'torneo_id' => (string)$tId,
                        'hora_inicio' => $horaInicio,
                        'torneo' => $torneoNombre,
                        'club' => $clubNombre,
                        'route' => '/jugador-campeonatos'
                    ];

                    if (!$isDryRun) {
                        notifyMatchReminder2H($conn, $pId, $titulo, $mensaje, $tipoNotif, $fechaRef, $extraPayload);
                    }

                    $summary["notifications_sent"]++;
                    $summary["details"][] = [
                        "type" => "torneo_americano",
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
              AND (r.estado IS NULL OR r.estado NOT IN ('cancelada', 'cancelado', 'Cancelada', 'Cancelado'))
        ";

        if (!$isForce) {
            $sqlEntrenamientos .= "
              AND CONCAT(r.fecha, ' ', r.hora_inicio) >= DATE_ADD(NOW(), INTERVAL $windowMinutesMin MINUTE)
              AND CONCAT(r.fecha, ' ', r.hora_inicio) <= DATE_ADD(NOW(), INTERVAL $windowMinutesMax MINUTE)
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

                // 4.A NOTIFY STUDENTS (ALUMNOS)
                $rawAlumnoIds = !empty($re['alumnos_ids']) ? explode(',', $re['alumnos_ids']) : [];
                $alumnoIds = array_filter(array_map('intval', $rawAlumnoIds), function($id) { return $id > 0; });
                $uniqueAlumnos = array_unique($alumnoIds);

                foreach ($uniqueAlumnos as $aId) {
                    if ($filterUserId && $aId !== $filterUserId) continue;

                    $tipoNotif = 'recordatorio_2h_clase';
                    $identificador = "clase #$reservaId";

                    if (hasBeenNotified2H($conn, $aId, $tipoNotif, $fechaRef, $identificador)) {
                        $summary["notifications_skipped_duplicate"]++;
                        continue;
                    }

                    $titulo = "🏋️‍♂️ ¡Tu clase de Pádel es en 2 horas!";
                    $mensaje = "Tu entrenamiento de $packNombre con $coachNombre comienza a las $horaInicio hrs en $clubNombre. ¡A darlo todo!";

                    $extraPayload = [
                        'event_type' => 'clase_padel',
                        'reserva_id' => (string)$reservaId,
                        'pack' => $packNombre,
                        'coach' => $coachNombre,
                        'hora' => $horaInicio,
                        'route' => '/jugador-reservas'
                    ];

                    if (!$isDryRun) {
                        notifyMatchReminder2H($conn, $aId, $titulo, $mensaje, $tipoNotif, $fechaRef, $extraPayload);
                    }

                    $summary["notifications_sent"]++;
                    $summary["details"][] = [
                        "type" => "clase_alumno",
                        "user_id" => $aId,
                        "event_id" => $reservaId,
                        "title" => $titulo,
                        "hora" => $horaInicio,
                        "status" => $isDryRun ? "simulated" : "sent"
                    ];
                }

                // 4.B NOTIFY COACH (PROFESOR)
                if ($coachId > 0 && (!$filterUserId || $coachId === $filterUserId)) {
                    $tipoCoach = 'recordatorio_2h_coach';
                    $identificadorCoach = "clase_coach #$reservaId";

                    if (!hasBeenNotified2H($conn, $coachId, $tipoCoach, $fechaRef, $identificadorCoach)) {
                        $tituloCoach = "📋 ¡Clase programada en 2 horas!";
                        $mensajeCoach = "Tienes sesión de $packNombre a las $horaInicio hrs en $clubNombre con $alumnosNombres.";

                        $extraPayloadCoach = [
                            'event_type' => 'clase_coach',
                            'reserva_id' => (string)$reservaId,
                            'pack' => $packNombre,
                            'alumnos' => $alumnosNombres,
                            'hora' => $horaInicio,
                            'route' => '/entrenador-entrenamientos'
                        ];

                        if (!$isDryRun) {
                            notifyMatchReminder2H($conn, $coachId, $tituloCoach, $mensajeCoach, $tipoCoach, $fechaRef, $extraPayloadCoach);
                        }

                        $summary["notifications_sent"]++;
                        $summary["details"][] = [
                            "type" => "clase_coach",
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

    // Response Output
    if ($isCli) {
        echo "=== [PadelBlox 2-Hour Reminder Engine] ===\n";
        echo "Timestamp: {$summary['timestamp']}\n";
        echo "Mode: {$summary['mode']}\n";
        echo "Partidos Amistosos: {$summary['processed_events']['reservas_cancha']}\n";
        echo "Partidos Liga: {$summary['processed_events']['liga_partidos']}\n";
        echo "Torneos Americanos: {$summary['processed_events']['torneos_americanos']}\n";
        echo "Entrenamientos: {$summary['processed_events']['entrenamientos']}\n";
        echo "Notificaciones Enviadas: {$summary['notifications_sent']}\n";
        echo "Duplicados Omitidos: {$summary['notifications_skipped_duplicate']}\n";
        echo "==========================================\n";
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
