<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../db.php';
require_once 'fcm_sender.php';

$action = $_GET['action'] ?? null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'JSON inválido']);
    exit;
}

try {
    if ($action === 'guardar_token') {
        guardarToken($conn, $input);
    } elseif ($action === 'enviar') {
        enviarNotificacion($conn, $input);
    } elseif ($action === 'programar_recordatorio') {
        programarRecordatorio($conn, $input);
    } elseif ($action === 'horarios_nuevos') {
        notificarHorariosDisponibles($conn, $input);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Acción no reconocida']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function guardarToken($conn, $input) {
    $userId = intval($input['user_id'] ?? 0);
    $token = $input['token'] ?? '';
    
    if ($userId <= 0 || empty($token)) {
        throw new Exception("user_id y token son requeridos");
    }

    $stmt = $conn->prepare("
        INSERT INTO fcm_tokens (user_id, token, created_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE token = VALUES(token)
    ");
    
    $stmt->bind_param('is', $userId, $token);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true, 'message' => 'Token guardado']);
}

function enviarNotificacion($conn, $input) {
    $userId = intval($input['user_id'] ?? 0);
    $titulo = $input['titulo'] ?? 'Notificación';
    $mensaje = $input['mensaje'] ?? '';
    $tipo = $input['tipo'] ?? 'general';
    $fechaRef = $input['fecha_referencia'] ?? null;
    
    if ($userId <= 0) {
        throw new Exception("user_id es requerido");
    }

    $stmt = $conn->prepare("
        INSERT INTO notificaciones (user_id, titulo, mensaje, tipo, fecha_referencia, leida)
        VALUES (?, ?, ?, ?, ?, 0)
    ");
    
    $stmt->bind_param('issss', $userId, $titulo, $mensaje, $tipo, $fechaRef);
    $stmt->execute();
    $stmt->close();

    // Enviar push notification (FCM) al usuario
    $stmtToken = $conn->prepare("SELECT token FROM fcm_tokens WHERE user_id = ?");
    if ($stmtToken) {
        $stmtToken->bind_param('i', $userId);
        $stmtToken->execute();
        $resToken = $stmtToken->get_result()->fetch_assoc();
        $stmtToken->close();

        if ($resToken && !empty($resToken['token'])) {
            send_fcm_push([$resToken['token']], $titulo, $mensaje);
        }
    }

    echo json_encode(['success' => true, 'message' => 'Notificación guardada y FCM enviado']);
}

function programarRecordatorio($conn, $input) {
    $userId = intval($input['user_id'] ?? 0);
    $packNombre = $input['pack_nombre'] ?? '';
    $fechaEnt = $input['fecha_entrenamiento'] ?? null;
    $horaInicio = $input['hora_inicio'] ?? '';

    if ($userId <= 0 || !$fechaEnt) {
        throw new Exception("user_id y fecha_entrenamiento son requeridos");
    }

    $fecha = new DateTime($fechaEnt);
    $fecha->modify('-1 day');
    $fechaRec = $fecha->format('Y-m-d');
    
    $titulo = "🎾 Recordatorio: Entrenamiento mañana";
    $mensaje = "Mañana tienes entrenamiento de $packNombre a las $horaInicio";
    $tipo = 'recordatorio_dia_anterior';

    $stmt = $conn->prepare("
        INSERT INTO recordatorios_programados (user_id, titulo, mensaje, tipo, fecha_programada, enviado)
        VALUES (?, ?, ?, ?, ?, 0)
    ");

    $stmt->bind_param('issss', $userId, $titulo, $mensaje, $tipo, $fechaRec);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true, 'message' => 'Recordatorio programado']);
}

function notificarHorariosDisponibles($conn, $input) {
    $entrenadorId = intval($input['entrenador_id'] ?? 0);
    $alumnoIds = $input['alumno_ids'] ?? [];

    if ($entrenadorId <= 0 || empty($alumnoIds)) {
        throw new Exception("entrenador_id y alumno_ids son requeridos");
    }

    $stmt = $conn->prepare("SELECT nombre FROM usuarios WHERE id = ?");
    $stmt->bind_param('i', $entrenadorId);
    $stmt->execute();
    $result = $stmt->get_result();
    $entrenador = $result->fetch_assoc();
    $stmt->close();

    $nombreEntrenador = $entrenador['nombre'] ?? 'Tu entrenador';
    $titulo = "📅 Nuevos horarios disponibles";
    $mensaje = "$nombreEntrenador ha publicado nuevos horarios para los próximos días";
    $tipo = 'horarios_nuevos';

    foreach ($alumnoIds as $alumnoId) {
        $alumnoId = intval($alumnoId);
        $stmt = $conn->prepare("
            INSERT INTO notificaciones (user_id, titulo, mensaje, tipo, leida)
            VALUES (?, ?, ?, ?, 0)
        ");

        $stmt->bind_param('isss', $alumnoId, $titulo, $mensaje, $tipo);
        $stmt->execute();
        $stmt->close();
    }

    echo json_encode(['success' => true, 'message' => 'Notificaciones guardadas']);
}
?>
