<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Error reporting
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once '../db.php';
require_once 'fcm_sender.php';

// Log errors to file
function logError($msg) {
    $logFile = "notifications_v2.log";
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $msg\n", FILE_APPEND);
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? null;

    logError("Recibida solicitud: método=$method, action=$action");

    if ($method === 'POST') {
        $rawInput = file_get_contents('php://input');
        logError("Raw input: " . $rawInput);
        
        $input = json_decode($rawInput, true);
        logError("Input decodificado: " . json_encode($input));

        if ($action === 'guardar_token') {
            guardarToken($input['user_id'] ?? null, $input['token'] ?? null);
        } elseif ($action === 'enviar') {
            enviarNotificacion($input);
        } elseif ($action === 'programar_recordatorio') {
            programarRecordatorio($input);
        } elseif ($action === 'horarios_nuevos') {
            notificarHorariosDisponibles($input);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Acción no reconocida: ' . $action]);
        }
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Método no permitido']);
    }
} catch (Exception $e) {
    logError("Exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Guardar token FCM del usuario
 */
function guardarToken($userId, $token) {
    global $mysqli;
    
    logError("guardarToken: userId=$userId");
    
    if (!$userId || !$token) {
        throw new Exception("user_id y token son requeridos");
    }

    $stmt = $mysqli->prepare("
        INSERT INTO fcm_tokens (user_id, token, created_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE token = VALUES(token), updated_at = NOW()
    ");
    
    if (!$stmt) {
        throw new Exception("Error preparando statement: " . $mysqli->error);
    }

    $stmt->bind_param('is', $userId, $token);
    if (!$stmt->execute()) {
        throw new Exception("Error ejecutando statement: " . $stmt->error);
    }
    $stmt->close();

    echo json_encode(['success' => true, 'message' => 'Token guardado']);
}

/**
 * Enviar notificación a un usuario
 */
function enviarNotificacion($data) {
    global $mysqli;
    
    logError("enviarNotificacion: " . json_encode($data));
    
    if (!is_array($data)) {
        throw new Exception("Data debe ser un array");
    }
    
    $userId = intval($data['user_id'] ?? 0);
    $titulo = $data['titulo'] ?? 'Notificación';
    $mensaje = $data['mensaje'] ?? '';
    $tipo = $data['tipo'] ?? 'general';
    $fechaReferencia = $data['fecha_referencia'] ?? null;

    logError("Parámetros: userId=$userId, titulo=$titulo, tipo=$tipo");

    if ($userId <= 0) {
        throw new Exception("user_id inválido: $userId");
    }

    // Guardar en BD
    $stmt = $mysqli->prepare("
        INSERT INTO notificaciones (user_id, titulo, mensaje, tipo, fecha_referencia, leida)
        VALUES (?, ?, ?, ?, ?, 0)
    ");
    
    if (!$stmt) {
        throw new Exception("Error preparando statement: " . $mysqli->error);
    }

    logError("Tipos: userId=i, titulo=s, mensaje=s, tipo=s, fechaRef=s");
    
    $stmt->bind_param('issss', $userId, $titulo, $mensaje, $tipo, $fechaReferencia);
    if (!$stmt->execute()) {
        throw new Exception("Error insertando notificación: " . $stmt->error);
    }
    
    logError("Notificación insertada exitosamente");
    $stmt->close();

    // Obtener token FCM del usuario para enviar push
    $stmtToken = $mysqli->prepare("SELECT token FROM fcm_tokens WHERE user_id = ?");
    if ($stmtToken) {
        $stmtToken->bind_param('i', $userId);
        $stmtToken->execute();
        $resToken = $stmtToken->get_result()->fetch_assoc();
        $stmtToken->close();

        if ($resToken && !empty($resToken['token'])) {
            logError("Enviando push FCM al token del usuario...");
            send_fcm_push([$resToken['token']], $titulo, $mensaje);
        }
    }

    echo json_encode(['success' => true, 'message' => 'Notificación guardada']);
}

/**
 * Programar recordatorio para el día anterior
 */
function programarRecordatorio($data) {
    global $mysqli;
    
    logError("programarRecordatorio: " . json_encode($data));
    
    $userId = intval($data['user_id'] ?? 0);
    $packNombre = $data['pack_nombre'] ?? '';
    $fechaEntrenamiento = $data['fecha_entrenamiento'] ?? null;
    $horaInicio = $data['hora_inicio'] ?? '';

    if ($userId <= 0 || !$fechaEntrenamiento) {
        throw new Exception("user_id y fecha_entrenamiento son requeridos");
    }

    // Calcular fecha del recordatorio (día anterior)
    $fecha = new DateTime($fechaEntrenamiento);
    $fecha->modify('-1 day');
    $fechaRecordatorio = $fecha->format('Y-m-d');
    
    $titulo = "🎾 Recordatorio: Entrenamiento mañana";
    $mensaje = "Mañana tienes entrenamiento de $packNombre a las $horaInicio";
    $tipo = 'recordatorio_dia_anterior';

    // Guardar en tabla de recordatorios programados
    $stmt = $mysqli->prepare("
        INSERT INTO recordatorios_programados (user_id, titulo, mensaje, tipo, fecha_programada, enviado)
        VALUES (?, ?, ?, ?, ?, 0)
    ");

    if (!$stmt) {
        throw new Exception("Error preparando statement: " . $mysqli->error);
    }

    $stmt->bind_param('issss', $userId, $titulo, $mensaje, $tipo, $fechaRecordatorio);
    if (!$stmt->execute()) {
        throw new Exception("Error insertando recordatorio: " . $stmt->error);
    }
    $stmt->close();

    echo json_encode(['success' => true, 'message' => 'Recordatorio programado']);
}

/**
 * Notificar nuevos horarios disponibles
 */
function notificarHorariosDisponibles($data) {
    global $mysqli;
    
    logError("notificarHorariosDisponibles: " . json_encode($data));
    
    $entrenadorId = intval($data['entrenador_id'] ?? 0);
    $alumnoIds = $data['alumno_ids'] ?? [];
    $horarios = $data['horarios'] ?? [];

    if ($entrenadorId <= 0 || empty($alumnoIds)) {
        throw new Exception("entrenador_id y alumno_ids son requeridos");
    }

    // Obtener nombre del entrenador
    $stmt = $mysqli->prepare("SELECT nombre FROM usuarios WHERE id = ?");
    if (!$stmt) {
        throw new Exception("Error preparando statement: " . $mysqli->error);
    }

    $stmt->bind_param('i', $entrenadorId);
    if (!$stmt->execute()) {
        throw new Exception("Error ejecutando statement: " . $stmt->error);
    }

    $result = $stmt->get_result();
    $entrenador = $result->fetch_assoc();
    $stmt->close();

    $nombreEntrenador = $entrenador['nombre'] ?? 'Tu entrenador';
    $titulo = "📅 Nuevos horarios disponibles";
    $mensaje = "$nombreEntrenador ha publicado nuevos horarios para los próximos días";
    $tipo = 'horarios_nuevos';

    // Enviar notificación a cada alumno
    foreach ($alumnoIds as $alumnoId) {
        $alumnoId = intval($alumnoId);
        $stmt = $mysqli->prepare("
            INSERT INTO notificaciones (user_id, titulo, mensaje, tipo, leida)
            VALUES (?, ?, ?, ?, 0)
        ");

        if (!$stmt) {
            throw new Exception("Error preparando statement: " . $mysqli->error);
        }

        $stmt->bind_param('isss', $alumnoId, $titulo, $mensaje, $tipo);
        if (!$stmt->execute()) {
            throw new Exception("Error insertando notificación: " . $stmt->error);
        }
        $stmt->close();
    }

    echo json_encode(['success' => true, 'message' => 'Notificaciones enviadas']);
}
?>
