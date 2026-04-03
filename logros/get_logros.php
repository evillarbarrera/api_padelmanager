<?php
/**
 * Get Logros (Achievements) for a player.
 * Returns all badges with unlock status and current progress.
 * 
 * GET /logros/get_logros.php?jugador_id=X
 */
header("Access-Control-Allow-Origin: *");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Methods: GET, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../auth/auth_helper.php";
$tokenUserId = validateToken();
if (!$tokenUserId) {
    sendUnauthorized();
}

header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, x-authorization, X-Authorization");
header("Content-Type: application/json");

require_once "../db.php";

$jugador_id = intval($_GET['jugador_id'] ?? 0);
if ($jugador_id <= 0) {
    http_response_code(400);
    echo json_encode(["error" => "jugador_id requerido"]);
    exit;
}

// Get all badges
$badges = [];
$res = $conn->query("SELECT * FROM logros ORDER BY orden ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $badges[] = $row;
    }
}

// Get unlocked badges for this player
$unlockedMap = [];
$res = $conn->query("SELECT logro_id, desbloqueado_en FROM jugador_logros WHERE jugador_id = $jugador_id");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $unlockedMap[$row['logro_id']] = $row['desbloqueado_en'];
    }
}

// Calculate progress for each badge
$logros = [];
$desbloqueados = 0;

foreach ($badges as $b) {
    $isUnlocked = isset($unlockedMap[$b['id']]);
    
    $progreso = getProgress($conn, $jugador_id, $b['codigo'], (int)$b['requisito_valor']);

    // Auto-unlock retroactivo (para usuarios que ya cumplían los requisitos)
    if (!$isUnlocked && $progreso['actual'] >= $progreso['requerido']) {
        $logroId = (int)$b['id'];
        $conn->query("INSERT IGNORE INTO jugador_logros (jugador_id, logro_id, notificado) VALUES ($jugador_id, $logroId, 1)");
        $isUnlocked = true;
        $unlockedMap[$b['id']] = date('Y-m-d H:i:s');
    }

    if ($isUnlocked) $desbloqueados++;

    $logros[] = [
        'id'                  => (int)$b['id'],
        'codigo'              => $b['codigo'],
        'nombre'              => $b['nombre'],
        'descripcion'         => $b['descripcion'],
        'icono'               => $b['icono'],
        'categoria'           => $b['categoria'],
        'color_badge'         => $b['color_badge'],
        'desbloqueado'        => $isUnlocked,
        'fecha_desbloqueo'    => $isUnlocked ? $unlockedMap[$b['id']] : null,
        'progreso_actual'     => $progreso['actual'],
        'progreso_requerido'  => $progreso['requerido']
    ];
}

$total = count($badges);
$porcentaje = $total > 0 ? round(($desbloqueados / $total) * 100) : 0;

echo json_encode([
    'success'        => true,
    'total'          => $total,
    'desbloqueados'  => $desbloqueados,
    'porcentaje'     => $porcentaje,
    'logros'         => $logros
]);

/**
 * Calculate current progress for a badge
 */
function getProgress($conn, $jugadorId, $codigo, $requisito) {
    $actual = 0;
    $requerido = $requisito;

    switch ($codigo) {
        case 'primera_clase':
        case 'maquina_imparable':
        case 'centurion':
            $r = $conn->query("SELECT COUNT(*) as c FROM alumno_asistencia WHERE jugador_id = $jugadorId AND estado_asistencia = 'presente'");
            $actual = ($r) ? (int)$r->fetch_assoc()['c'] : 0;
            break;

        case 'racha_fuego':
            // Count current consecutive 'presente' streak
            $r = $conn->query("
                SELECT aa.estado_asistencia 
                FROM alumno_asistencia aa
                LEFT JOIN reservas r ON r.id = aa.reserva_id
                WHERE aa.jugador_id = $jugadorId
                ORDER BY COALESCE(r.fecha, aa.id) DESC
                LIMIT 10
            ");
            $streak = 0;
            if ($r) {
                while ($row = $r->fetch_assoc()) {
                    if ($row['estado_asistencia'] === 'presente') {
                        $streak++;
                    } else {
                        break;
                    }
                }
            }
            $actual = $streak;
            $requerido = 4;
            break;

        case 'madrugador':
            $r = $conn->query("
                SELECT COUNT(*) as c 
                FROM alumno_asistencia aa
                JOIN reservas r ON r.id = aa.reserva_id
                WHERE aa.jugador_id = $jugadorId 
                AND aa.estado_asistencia = 'presente'
                AND r.hora_inicio < '10:00:00'
            ");
            $actual = ($r) ? (int)$r->fetch_assoc()['c'] : 0;
            break;

        case 'primera_evaluacion':
            $r = $conn->query("SELECT COUNT(*) as c FROM evaluaciones WHERE jugador_id = $jugadorId");
            $actual = ($r) ? (int)$r->fetch_assoc()['c'] : 0;
            $requerido = 1;
            break;

        case 'nivel_up':
            // Show the improvement amount
            $r = $conn->query("
                SELECT promedio_general FROM evaluaciones 
                WHERE jugador_id = $jugadorId ORDER BY fecha DESC, id DESC LIMIT 2
            ");
            if ($r && $r->num_rows >= 2) {
                $evals = [];
                while ($row = $r->fetch_assoc()) $evals[] = (float)$row['promedio_general'];
                $diff = $evals[0] - $evals[1];
                $actual = max(0, round($diff, 1));
            }
            $requerido = 1;
            break;

        case 'golpe_perfecto':
            // Show max score found
            $maxScore = 0;
            $r = $conn->query("SELECT scores FROM evaluaciones WHERE jugador_id = $jugadorId");
            if ($r) {
                while ($row = $r->fetch_assoc()) {
                    $scores = json_decode($row['scores'], true);
                    if (is_array($scores)) {
                        foreach ($scores as $golpe => $metrics) {
                            if (is_array($metrics)) {
                                foreach ($metrics as $val) {
                                    if (is_numeric($val) && $val > $maxScore) $maxScore = (float)$val;
                                }
                            }
                        }
                    }
                }
            }
            $actual = $maxScore;
            $requerido = 9;
            break;

        case 'evolucion_total':
            // Count ascending evaluations in sequence
            $r = $conn->query("
                SELECT promedio_general FROM evaluaciones 
                WHERE jugador_id = $jugadorId ORDER BY fecha ASC, id ASC
            ");
            $ascending = 0;
            $prev = -1;
            if ($r) {
                while ($row = $r->fetch_assoc()) {
                    $val = (float)$row['promedio_general'];
                    if ($prev >= 0 && $val > $prev) {
                        $ascending++;
                    } else if ($prev >= 0) {
                        $ascending = 0; // reset
                    }
                    $prev = $val;
                }
            }
            $actual = min($ascending + 1, 3); // +1 because first eval counts
            $requerido = 3;
            break;

        case 'ojo_ia':
        case 'analista_pro':
            $r = $conn->query("
                SELECT COUNT(*) as c FROM entrenamiento_videos 
                WHERE ai_report IS NOT NULL AND ai_report != 'null' AND ai_report != ''
            ");
            $actual = ($r) ? (int)$r->fetch_assoc()['c'] : 0;
            break;

        case 'golpe_maestro':
            // Show max AI score
            $maxAI = 0;
            $r = $conn->query("
                SELECT ai_report FROM entrenamiento_videos 
                WHERE ai_report IS NOT NULL AND ai_report != 'null' AND ai_report != ''
            ");
            if ($r) {
                while ($row = $r->fetch_assoc()) {
                    $report = json_decode($row['ai_report'], true);
                    if (is_array($report) && isset($report['score'])) {
                        $maxAI = max($maxAI, (int)$report['score']);
                    }
                }
            }
            $actual = $maxAI;
            $requerido = 85;
            break;
    }

    return [
        'actual'    => $actual,
        'requerido' => $requerido
    ];
}
?>
