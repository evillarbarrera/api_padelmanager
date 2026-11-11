<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, x-authorization, X-Authorization');
header('Content-Type: application/json');

require_once '../db.php';

// Procesar requests
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

try {
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        if ($action === 'guardar_token') {
            guardarToken($input['user_id'], $input['token']);
        } elseif ($action === 'enviar') {
            enviarNotificacion($input);
        } elseif ($action === 'programar_recordatorio') {
            programarRecordatorio($input);
        } elseif ($action === 'horarios_nuevos') {
            notificarHorariosDisponibles($input);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Acción no reconocida']);
        }
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Método no permitido']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Guardar token FCM del usuario
 */
function guardarToken($userId, $token) {
    global $conn, $mysqli;
    $db = $conn ?? $mysqli;
    
    $userId = intval($userId);
    $token = trim($token);

    if ($userId <= 0 || empty($token)) {
        throw new Exception("user_id y token son requeridos");
    }

    // Desvincular este token de otros usuarios si fue reutilizado
    $stCleanOther = $db->prepare("DELETE FROM fcm_tokens WHERE token = ? AND user_id != ?");
    if ($stCleanOther) {
        $stCleanOther->bind_param('si', $token, $userId);
        $stCleanOther->execute();
        $stCleanOther->close();
    }

    try {
        $db->query("UPDATE usuarios SET fcm_token = NULL WHERE fcm_token = '" . $db->real_escape_string($token) . "' AND id != $userId");
    } catch (Throwable $e) {}

    // Evitar duplicados del mismo token para este usuario
    $stCleanSame = $db->prepare("DELETE FROM fcm_tokens WHERE token = ? AND user_id = ?");
    if ($stCleanSame) {
        $stCleanSame->bind_param('si', $token, $userId);
        $stCleanSame->execute();
        $stCleanSame->close();
    }

    $stmt = $db->prepare("INSERT INTO fcm_tokens (user_id, token, created_at) VALUES (?, ?, NOW())");
    if (!$stmt) {
        throw new Exception("Error preparando statement: " . $db->error);
    }

    $stmt->bind_param('is', $userId, $token);
    if (!$stmt->execute()) {
        throw new Exception("Error ejecutando statement: " . $stmt->error);
    }
    $stmt->close();

    // Mantener solo los últimos 3 tokens
    @$db->query("
        DELETE FROM fcm_tokens 
        WHERE user_id = $userId 
          AND id NOT IN (
              SELECT id FROM (
                  SELECT id FROM fcm_tokens WHERE user_id = $userId ORDER BY id DESC LIMIT 3
              ) as tmp
          )
    ");

    echo json_encode(['success' => true, 'message' => 'Token guardado']);
}

/**
 * Enviar notificación a un usuario
 */
function enviarNotificacion($data) {
    global $conn;
$db = $conn ?? $mysqli;
    
    $userId = $data['user_id'] ?? null;
    $titulo = $data['titulo'] ?? 'Notificación';
    $mensaje = $data['mensaje'] ?? '';
    $tipo = $data['tipo'] ?? 'general';
    $fechaReferencia = $data['fecha_referencia'] ?? null;

    if (!$userId) {
        throw new Exception("user_id es requerido");
    }

    // Guardar en BD
    $stmt = $db->prepare("
        INSERT INTO notificaciones (user_id, titulo, mensaje, tipo, fecha_referencia, leida)
        VALUES (?, ?, ?, ?, ?, 0)
    ");
    
    if (!$stmt) {
        throw new Exception("Error preparando statement: " . $db->error);
    }

    $stmt->bind_param('issss', $userId, $titulo, $mensaje, $tipo, $fechaReferencia);
    if (!$stmt->execute()) {
        throw new Exception("Error ejecutando statement: " . $stmt->error);
    }
    $stmt->close();

    // TODO: Enviar por FCM aquí (implementar después con librería oficial)
    // Por ahora solo guardamos en BD
    
    echo json_encode(['success' => true, 'message' => 'Notificación guardada']);
}

/**
 * Programar recordatorio para el día anterior
 */
function programarRecordatorio($data) {
    global $conn, $mysqli;
    $db = $conn ?? $mysqli;
    
    $userId = intval($data['user_id'] ?? 0);
    $packNombre = trim($data['pack_nombre'] ?? 'Entrenamiento');
    $fechaEntrenamiento = $data['fecha_entrenamiento'] ?? null;
    $horaInicio = trim($data['hora_inicio'] ?? '');
    $reservaId = intval($data['reserva_id'] ?? 0);

    if ($userId <= 0 || !$fechaEntrenamiento) {
        throw new Exception("user_id y fecha_entrenamiento son requeridos");
    }

    try {
        $checkColRes = $db->query("SHOW COLUMNS FROM recordatorios_programados LIKE 'reserva_id'");
        if ($checkColRes && $checkColRes->num_rows === 0) {
            $db->query("ALTER TABLE recordatorios_programados ADD COLUMN reserva_id INT NULL");
        }
    } catch (Throwable $e) {}

    $nowStr = date('Y-m-d H:i:s');
    $insertedCount = 0;

    // Calcular fecha del recordatorio (día anterior a las 09:00 AM)
    $fecha = new DateTime($fechaEntrenamiento);
    $fecha->modify('-1 day');
    $fechaRecordatorio = $fecha->format('Y-m-d') . ' 09:00:00';

    if ($fechaRecordatorio > $nowStr) {
        $titulo = "🥎 Recordatorio: Entrenamiento mañana";
        $mensaje = "Mañana tienes entrenamiento de $packNombre a las $horaInicio";
        $tipo = 'recordatorio_dia_anterior';

        $stmt = $db->prepare("
            INSERT INTO recordatorios_programados (user_id, titulo, mensaje, tipo, fecha_programada, reserva_id, enviado)
            VALUES (?, ?, ?, ?, ?, ?, 0)
        ");

        if ($stmt) {
            $stmt->bind_param('issssi', $userId, $titulo, $mensaje, $tipo, $fechaRecordatorio, $reservaId);
            $stmt->execute();
            $stmt->close();
            $insertedCount++;
        }
    }
    
    echo json_encode(['success' => true, 'message' => "Recordatorio procesado (programados: $insertedCount)"]);
}

/**
 * Notificar nuevos horarios disponibles
 */
function notificarHorariosDisponibles($data) {
    global $conn;
$db = $conn ?? $mysqli;
    
    $entrenadorId = $data['entrenador_id'] ?? null;
    $alumnoIds = $data['alumno_ids'] ?? [];
    $horarios = $data['horarios'] ?? [];

    if (!$entrenadorId || empty($alumnoIds)) {
        throw new Exception("entrenador_id y alumno_ids son requeridos");
    }

    // Obtener nombre del entrenador
    $stmt = $db->prepare("SELECT nombre FROM usuarios WHERE id = ?");
    if (!$stmt) {
        throw new Exception("Error preparando statement: " . $db->error);
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
        $stmt = $db->prepare("
            INSERT INTO notificaciones (user_id, titulo, mensaje, tipo, leida)
            VALUES (?, ?, ?, ?, 0)
        ");

        if (!$stmt) {
            throw new Exception("Error preparando statement: " . $db->error);
        }

        $stmt->bind_param('isss', $alumnoId, $titulo, $mensaje, $tipo);
        if (!$stmt->execute()) {
            throw new Exception("Error ejecutando statement: " . $stmt->error);
        }
        $stmt->close();
    }

    echo json_encode(['success' => true, 'message' => 'Notificaciones enviadas']);
}
?>

