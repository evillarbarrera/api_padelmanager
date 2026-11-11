<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, x-authorization, X-Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../db.php';
require_once 'fcm_sender.php';
require_once 'notificaciones_helper.php';

$action = $_GET['action'] ?? null;

// DEBUG GLOBAL: Log every single hit to this file
$rawInput = file_get_contents('php://input');
$debugMsg = date('Y-m-d H:i:s') . " - ACCESS: Action=$action - IP=" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . " - Body=$rawInput" . PHP_EOL;
file_put_contents(__DIR__ . '/access_debug.log', $debugMsg, FILE_APPEND);

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
    } elseif ($action === 'recordatorio_partido_2h') {
        programarRecordatorioPartido2H($conn, $input);
    } elseif ($action === 'reset_tokens') {
        resetUserTokens($conn, $input);
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
    $token = trim($input['token'] ?? '');
    
    // Log the attempt
    $logMsg = date('Y-m-d H:i:s') . " - GuardarToken Attempt: User $userId - Token: $token" . PHP_EOL;
    @file_put_contents(__DIR__ . '/token_requests.log', $logMsg, FILE_APPEND);

    if ($userId <= 0 || empty($token)) {
        throw new Exception("user_id y token son requeridos");
    }

    // 1. REGLA CLAVE: Un dispositivo físico solo pertenece a un usuario a la vez.
    // Si este token estaba registrado bajo otro usuario (ej: entrenador, admin o login previo en el mismo celular),
    // se desvincula de los otros usuarios para que no reciba notificaciones ajenas.
    $stCleanOther = $conn->prepare("DELETE FROM fcm_tokens WHERE token = ? AND user_id != ?");
    if ($stCleanOther) {
        $stCleanOther->bind_param('si', $token, $userId);
        $stCleanOther->execute();
        $stCleanOther->close();
    }

    // Desvincular también en la tabla usuarios si existía la columna fcm_token
    try {
        $conn->query("UPDATE usuarios SET fcm_token = NULL WHERE fcm_token = '" . $conn->real_escape_string($token) . "' AND id != $userId");
    } catch (Throwable $e) {}

    // 2. Evitar filas duplicadas del mismo token para el mismo usuario
    $stCleanSame = $conn->prepare("DELETE FROM fcm_tokens WHERE token = ? AND user_id = ?");
    if ($stCleanSame) {
        $stCleanSame->bind_param('si', $token, $userId);
        $stCleanSame->execute();
        $stCleanSame->close();
    }

    // 3. Insertar el token actualizado
    $stmt = $conn->prepare("INSERT INTO fcm_tokens (user_id, token, created_at) VALUES (?, ?, NOW())");
    if ($stmt) {
        $stmt->bind_param('is', $userId, $token);
        $stmt->execute();
        $stmt->close();
    }

    // 4. Limitar a un máximo de 3 tokens activos por usuario (mantener solo los más recientes)
    @$conn->query("
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
    $packNombre = trim($input['pack_nombre'] ?? 'Entrenamiento');
    $fechaEnt = $input['fecha_entrenamiento'] ?? null;
    $horaInicio = trim($input['hora_inicio'] ?? '');
    $reservaId = intval($input['reserva_id'] ?? 0);

    if ($userId <= 0 || !$fechaEnt) {
        throw new Exception("user_id y fecha_entrenamiento son requeridos");
    }

    // Asegurar compatibilidad de columna reserva_id
    try {
        $checkColRes = $conn->query("SHOW COLUMNS FROM recordatorios_programados LIKE 'reserva_id'");
        if ($checkColRes && $checkColRes->num_rows === 0) {
            $conn->query("ALTER TABLE recordatorios_programados ADD COLUMN reserva_id INT NULL");
        }
    } catch (Throwable $e) {}

    $nowStr = date('Y-m-d H:i:s');
    $insertedCount = 0;

    $stmt = $conn->prepare("
        INSERT INTO recordatorios_programados (user_id, titulo, mensaje, tipo, fecha_programada, reserva_id, enviado)
        VALUES (?, ?, ?, ?, ?, ?, 0)
    ");

    if (!$stmt) {
        // Fallback si no tiene columna reserva_id
        $stmt = $conn->prepare("
            INSERT INTO recordatorios_programados (user_id, titulo, mensaje, tipo, fecha_programada, enviado)
            VALUES (?, ?, ?, ?, ?, 0)
        ");
        $hasResCol = false;
    } else {
        $hasResCol = true;
    }

    // 1. Recordatorio 1 día antes a las 09:00 AM (SOLO si la fecha futura es posterior al momento actual)
    $fecha1 = new DateTime($fechaEnt);
    $fecha1->modify('-1 day');
    $fechaRec1 = $fecha1->format('Y-m-d') . ' 09:00:00';
    
    if ($fechaRec1 > $nowStr) {
        $titulo1 = "🥎 Mañana: Clase de Pádel";
        $mensaje1 = "Recuerda tu entrenamiento de $packNombre mañana a las $horaInicio. ¡Prepárate!";
        $tipo1 = 'recordatorio_24h';

        if ($hasResCol) {
            $stmt->bind_param('issssi', $userId, $titulo1, $mensaje1, $tipo1, $fechaRec1, $reservaId);
        } else {
            $stmt->bind_param('issss', $userId, $titulo1, $mensaje1, $tipo1, $fechaRec1);
        }
        $stmt->execute();
        $insertedCount++;
    }

    // 2. Recordatorio 1 hora antes (SOLO si la hora calculada es en el futuro)
    if (!empty($horaInicio)) {
        try {
            $fecha2 = new DateTime($fechaEnt . ' ' . $horaInicio);
            $fecha2->modify('-1 hour');
            $fechaRec2 = $fecha2->format('Y-m-d H:i:s');
            
            if ($fechaRec2 > $nowStr) {
                $titulo2 = "⚡ ¡En 1 hora!";
                $mensaje2 = "Tu clase de $packNombre comienza a las $horaInicio. ¡Te esperamos!";
                $tipo2 = 'recordatorio_1h';

                if ($hasResCol) {
                    $stmt->bind_param('issssi', $userId, $titulo2, $mensaje2, $tipo2, $fechaRec2, $reservaId);
                } else {
                    $stmt->bind_param('issss', $userId, $titulo2, $mensaje2, $tipo2, $fechaRec2);
                }
                $stmt->execute();
                $insertedCount++;
            }
        } catch (Throwable $eTime) {}
    }
    
    if ($stmt) {
        $stmt->close();
    }

    echo json_encode([
        'success' => true, 
        'message' => "Recordatorios programados (activos: $insertedCount)",
        'programados' => $insertedCount
    ]);
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

function programarRecordatorioPartido2H($conn, $input) {
    $userId = intval($input['user_id'] ?? 0);
    $matchId = $input['match_id'] ?? null;
    $tipoEvento = $input['tipo_evento'] ?? 'reserva'; // 'reserva' | 'liga' | 'clase'
    $fecha = $input['fecha'] ?? null;
    $horaInicio = $input['hora_inicio'] ?? null;
    $cancha = $input['cancha'] ?? 'Cancha Central';
    $club = $input['club'] ?? 'Club Pádel';

    if ($userId <= 0 || !$fecha || !$horaInicio) {
        throw new Exception("user_id, fecha y hora_inicio son requeridos");
    }

    $dateTimeStr = "$fecha $horaInicio";
    $matchDateTime = new DateTime($dateTimeStr);
    $reminderDateTime = clone $matchDateTime;
    $reminderDateTime->modify('-2 hours');
    $fechaProgramada = $reminderDateTime->format('Y-m-d H:i:s');

    $titulo = "🎾 ¡Tu partido en $cancha es en 2 horas!";
    if ($tipoEvento === 'liga') {
        $titulo = "🏆 ¡Partido de Liga en 2 horas!";
    } elseif ($tipoEvento === 'clase') {
        $titulo = "🏋️‍♂️ ¡Tu clase de Pádel es en 2 horas!";
    }

    $mensaje = "Hoy a las $horaInicio hrs en $club. Prepárate con tiempo y activa tu Apple Watch.";
    $tipo = "recordatorio_2h_" . $tipoEvento;

    // Check if table recordatorios_programados exists
    $stmt = $conn->prepare("
        INSERT INTO recordatorios_programados (user_id, titulo, mensaje, tipo, fecha_programada, enviado)
        VALUES (?, ?, ?, ?, ?, 0)
    ");

    if ($stmt) {
        $stmt->bind_param('issss', $userId, $titulo, $mensaje, $tipo, $fechaProgramada);
        $stmt->execute();
        $stmt->close();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Recordatorio de 2 horas programado correctamente',
        'fecha_programada' => $fechaProgramada,
        'titulo' => $titulo
    ]);
}
?>
