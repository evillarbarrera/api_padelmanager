<?php
/**
 * Achievement Engine - check_achievements.php
 * Evaluates all achievement conditions for a given player.
 * Called as a hook from other endpoints (asistencia, evaluaciones, video IA).
 * 
 * Usage: require_once and call checkAchievements($conn, $jugadorId)
 * Returns array of newly unlocked achievements (empty if none).
 */

require_once __DIR__ . "/../notifications/notificaciones_helper.php";

function checkAchievements($conn, $jugadorId) {
    $jugadorId = intval($jugadorId);
    if ($jugadorId <= 0) return [];

    // Ensure tables exist (silent)
    $conn->query("CREATE TABLE IF NOT EXISTS logros (
        id INT AUTO_INCREMENT PRIMARY KEY,
        codigo VARCHAR(50) UNIQUE NOT NULL,
        nombre VARCHAR(100) NOT NULL,
        descripcion VARCHAR(255) NOT NULL,
        icono VARCHAR(10) NOT NULL,
        categoria ENUM('constancia','progreso','ia') NOT NULL,
        requisito_valor INT DEFAULT 1,
        orden INT DEFAULT 0,
        color_badge VARCHAR(7) DEFAULT '#CCFF00'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS jugador_logros (
        id INT AUTO_INCREMENT PRIMARY KEY,
        jugador_id INT NOT NULL,
        logro_id INT NOT NULL,
        desbloqueado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        notificado BOOLEAN DEFAULT FALSE,
        UNIQUE KEY unique_jugador_logro (jugador_id, logro_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 1. Get already unlocked achievement codes
    $unlocked = [];
    $res = $conn->query("SELECT l.codigo FROM jugador_logros jl JOIN logros l ON l.id = jl.logro_id WHERE jl.jugador_id = $jugadorId");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $unlocked[] = $row['codigo'];
        }
    }

    // 2. Get all badge definitions
    $badges = [];
    $res = $conn->query("SELECT * FROM logros ORDER BY orden");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $badges[] = $row;
        }
    }

    if (empty($badges)) return [];

    $nuevosLogros = [];

    foreach ($badges as $badge) {
        // Skip if already unlocked
        if (in_array($badge['codigo'], $unlocked)) continue;

        $achieved = false;

        switch ($badge['codigo']) {

            // ═══════════════════════════════════════
            // CONSTANCIA
            // ═══════════════════════════════════════

            case 'primera_clase':
                // 1 attendance with estado_asistencia = 'presente'
                $r = $conn->query("SELECT COUNT(*) as c FROM alumno_asistencia WHERE jugador_id = $jugadorId AND estado_asistencia = 'presente'");
                $achieved = ($r && $r->fetch_assoc()['c'] >= 1);
                break;

            case 'racha_fuego':
                // Last 4 attendances all 'presente' (ordered by date desc via reservation)
                $r = $conn->query("
                    SELECT aa.estado_asistencia 
                    FROM alumno_asistencia aa
                    LEFT JOIN reservas r ON r.id = aa.reserva_id
                    WHERE aa.jugador_id = $jugadorId
                    ORDER BY COALESCE(r.fecha, aa.id) DESC
                    LIMIT 4
                ");
                if ($r && $r->num_rows >= 4) {
                    $allPresent = true;
                    while ($row = $r->fetch_assoc()) {
                        if ($row['estado_asistencia'] !== 'presente') {
                            $allPresent = false;
                            break;
                        }
                    }
                    $achieved = $allPresent;
                }
                break;

            case 'maquina_imparable':
                // 10 total attendances
                $r = $conn->query("SELECT COUNT(*) as c FROM alumno_asistencia WHERE jugador_id = $jugadorId AND estado_asistencia = 'presente'");
                $achieved = ($r && $r->fetch_assoc()['c'] >= 10);
                break;

            case 'centurion':
                // 50 total attendances
                $r = $conn->query("SELECT COUNT(*) as c FROM alumno_asistencia WHERE jugador_id = $jugadorId AND estado_asistencia = 'presente'");
                $achieved = ($r && $r->fetch_assoc()['c'] >= 50);
                break;

            case 'madrugador':
                // 5 classes before 10:00
                $r = $conn->query("
                    SELECT COUNT(*) as c 
                    FROM alumno_asistencia aa
                    JOIN reservas r ON r.id = aa.reserva_id
                    WHERE aa.jugador_id = $jugadorId 
                    AND aa.estado_asistencia = 'presente'
                    AND r.hora_inicio < '10:00:00'
                ");
                $achieved = ($r && $r->fetch_assoc()['c'] >= 5);
                break;

            // ═══════════════════════════════════════
            // PROGRESO
            // ═══════════════════════════════════════

            case 'primera_evaluacion':
                $r = $conn->query("SELECT COUNT(*) as c FROM evaluaciones WHERE jugador_id = $jugadorId");
                $achieved = ($r && $r->fetch_assoc()['c'] >= 1);
                break;

            case 'nivel_up':
                // Compare last 2 evaluations: newer must be higher
                $r = $conn->query("
                    SELECT promedio_general 
                    FROM evaluaciones 
                    WHERE jugador_id = $jugadorId 
                    ORDER BY fecha DESC, id DESC
                    LIMIT 2
                ");
                if ($r && $r->num_rows >= 2) {
                    $evals = [];
                    while ($row = $r->fetch_assoc()) {
                        $evals[] = (float)$row['promedio_general'];
                    }
                    // evals[0] is newest, evals[1] is older
                    $achieved = ($evals[0] > $evals[1] && ($evals[0] - $evals[1]) >= 1);
                }
                break;

            case 'golpe_perfecto':
                // Any evaluation where a single metric score >= 9
                $r = $conn->query("SELECT scores FROM evaluaciones WHERE jugador_id = $jugadorId");
                if ($r) {
                    while ($row = $r->fetch_assoc()) {
                        $scores = json_decode($row['scores'], true);
                        if (is_array($scores)) {
                            // Función anidada para buscar el puntaje >= 9 en cualquier nivel
                            $findHigh = false;
                            $checkRecursive = function($data) use (&$checkRecursive, &$findHigh) {
                                if (!is_array($data) || $findHigh) return;
                                foreach ($data as $v) {
                                    if (is_numeric($v) && floatval($v) >= 9) {
                                        $findHigh = true;
                                        return;
                                    } else if (is_array($v)) {
                                        $checkRecursive($v);
                                    }
                                }
                            };
                            $checkRecursive($scores);
                            if ($findHigh) {
                                $achieved = true;
                                break;
                            }
                        }
                    }
                }
                break;

            case 'evolucion_total':
                // 3 evaluations with ascending average
                $r = $conn->query("
                    SELECT promedio_general 
                    FROM evaluaciones 
                    WHERE jugador_id = $jugadorId 
                    ORDER BY fecha ASC, id ASC
                ");
                if ($r && $r->num_rows >= 3) {
                    $promedios = [];
                    while ($row = $r->fetch_assoc()) {
                        $promedios[] = (float)$row['promedio_general'];
                    }
                    // Check if there are 3 consecutive ascending values
                    for ($i = 0; $i <= count($promedios) - 3; $i++) {
                        if ($promedios[$i] < $promedios[$i+1] && $promedios[$i+1] < $promedios[$i+2]) {
                            $achieved = true;
                            break;
                        }
                    }
                }
                break;

            // ═══════════════════════════════════════
            // IA & VIDEO
            // ═══════════════════════════════════════

            case 'ojo_ia':
                // 1 video with AI report
                $r = $conn->query("
                    SELECT COUNT(*) as c 
                    FROM entrenamiento_videos 
                    WHERE jugador_id = $jugadorId 
                    AND ai_report IS NOT NULL 
                    AND ai_report != 'null'
                    AND ai_report != ''
                ");
                // Fallback: also check by entrenamiento ownership
                if (!$r || $r->fetch_assoc()['c'] < 1) {
                    $r = $conn->query("
                        SELECT COUNT(*) as c 
                        FROM entrenamiento_videos ev
                        WHERE ev.ai_report IS NOT NULL 
                        AND ev.ai_report != 'null'
                        AND ev.ai_report != ''
                        AND EXISTS (
                            SELECT 1 FROM reserva_jugadores rj 
                            JOIN reservas res ON res.id = rj.reserva_id
                            WHERE rj.jugador_id = $jugadorId
                        )
                    ");
                }
                $achieved = ($r && $r->fetch_assoc()['c'] >= 1);
                break;

            case 'golpe_maestro':
                // Video AI score >= 85
                $r = $conn->query("
                    SELECT ai_report 
                    FROM entrenamiento_videos 
                    WHERE ai_report IS NOT NULL 
                    AND ai_report != 'null'
                    AND ai_report != ''
                ");
                if ($r) {
                    while ($row = $r->fetch_assoc()) {
                        $report = json_decode($row['ai_report'], true);
                        if (is_array($report) && isset($report['score']) && $report['score'] >= 85) {
                            $achieved = true;
                            break;
                        }
                    }
                }
                break;

            case 'analista_pro':
                // 5+ videos analyzed
                $r = $conn->query("
                    SELECT COUNT(*) as c 
                    FROM entrenamiento_videos 
                    WHERE ai_report IS NOT NULL 
                    AND ai_report != 'null'
                    AND ai_report != ''
                ");
                $achieved = ($r && $r->fetch_assoc()['c'] >= 5);
                break;
        }

        // 3. If achieved, INSERT and notify
        if ($achieved) {
            $stmtInsert = $conn->prepare("INSERT IGNORE INTO jugador_logros (jugador_id, logro_id) VALUES (?, ?)");
            $badgeId = (int)$badge['id'];
            $stmtInsert->bind_param("ii", $jugadorId, $badgeId);
            $stmtInsert->execute();

            if ($conn->affected_rows > 0) {
                // New achievement!
                $nuevosLogros[] = [
                    'codigo'      => $badge['codigo'],
                    'nombre'      => $badge['nombre'],
                    'icono'       => $badge['icono'],
                    'descripcion' => $badge['descripcion'],
                    'color_badge' => $badge['color_badge']
                ];

                // Push notification
                $titlePush = "🏅 ¡Logro Desbloqueado!";
                $msgPush = "{$badge['icono']} {$badge['nombre']} — {$badge['descripcion']}";
                notifyUser($conn, $jugadorId, $titlePush, $msgPush, 'logro_desbloqueado');
            }
        }
    }

    return $nuevosLogros;
}
?>
