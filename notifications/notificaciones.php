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
require_once 'notificaciones_helper.php';

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
    
    // Log the attempt
    $logMsg = date('Y-m-d H:i:s') . " - GuardarToken Attempt: User $userId - Token: $token" . PHP_EOL;
    file_put_contents(__DIR__ . '/token_requests.log', $logMsg, FILE_APPEND);

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

    notifyUser($conn, $userId, $titulo, $mensaje, $tipo, $fechaRef);

    echo json_encode(['success' => true, 'message' => 'Notificación enviada']);
}

function programarRecordatorio($conn, $input) {
    $userId = intval($input['user_id'] ?? 0);
    $packNombre = $input['pack_nombre'] ?? '';
    $fechaEnt = $input['fecha_entrenamiento'] ?? null;
    $horaInicio = $input['hora_inicio'] ?? '';

    if ($userId <= 0 || !$fechaEnt) {
        throw new Exception("user_id y fecha_entrenamiento son requeridos");
    }

    $stmt = $conn->prepare("
        INSERT INTO recordatorios_programados (user_id, titulo, mensaje, tipo, fecha_programada, enviado)
        VALUES (?, ?, ?, ?, ?, 0)
    ");

    if (!$stmt) {
        throw new Exception("Error preparando statement: " . $conn->error);
    }

    // 1. Recordatorio 1 día antes (si el entrenamiento no es hoy o mañana muy temprano)
    // Lo programamos para el día anterior a las 09:00 AM
    $fecha1 = new DateTime($fechaEnt);
    $fecha1->modify('-1 day');
    $fechaRec1 = $fecha1->format('Y-m-d') . ' 09:00:00';
    
    $titulo1 = "🎾 Mañana: Clase de Pádel";
    $mensaje1 = "Recuerda tu entrenamiento de $packNombre mañana a las $horaInicio. ¡Prepárate!";
    $tipo1 = 'recordatorio_24h';

    // 2. Recordatorio 1 hora antes
    // Combinamos fecha y hora
    $fecha2 = new DateTime($fechaEnt . ' ' . $horaInicio);
    $fecha2->modify('-1 hour');
    $fechaRec2 = $fecha2->format('Y-m-d H:i:s');
    
    $titulo2 = "⚡ ¡En 1 hora!";
    $mensaje2 = "Tu clase de $packNombre comienza a las $horaInicio. ¡Te esperamos!";
    $tipo2 = 'recordatorio_1h';

    // Insertar el de 24h
    $stmt->bind_param('issss', $userId, $titulo1, $mensaje1, $tipo1, $fechaRec1);
    $stmt->execute();
    
    // Insertar el de 1h
    $stmt->bind_param('issss', $userId, $titulo2, $mensaje2, $tipo2, $fechaRec2);
    $stmt->execute();
    
    $stmt->close();

    echo json_encode(['success' => true, 'message' => 'Recordatorios programados (24h y 1h)']);
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
        notifyUser($conn, $alumnoId, $titulo, $mensaje, $tipo);
    }

    echo json_encode(['success' => true, 'message' => 'Notificaciones guardadas y Push enviadas']);
}
?>
