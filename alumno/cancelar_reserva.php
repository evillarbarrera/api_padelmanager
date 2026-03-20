<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Access-Control-Max-Age: 86400");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$headers = getallheaders();
require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}

try {
    require_once "../db.php";

    // Leer el body de la solicitud
    $body = file_get_contents("php://input");
    $data = json_decode($body, true);
    
    // Validar que se recibieron los datos
    if (!$data || !is_array($data)) {
        http_response_code(400);
        echo json_encode([
            "error" => "JSON inválido",
            "body" => $body,
            "decoded" => $data
        ]);
        exit;
    }
    
    $reserva_id = isset($data['reserva_id']) ? intval($data['reserva_id']) : 0;
    $jugador_id = isset($data['jugador_id']) ? intval($data['jugador_id']) : 0;

    if ($reserva_id <= 0 || $jugador_id <= 0) {
        http_response_code(400);
        echo json_encode([
            "error" => "reserva_id y jugador_id son obligatorios y deben ser números válidos",
            "reserva_id" => $reserva_id,
            "jugador_id" => $jugador_id,
            "data" => $data
        ]);
        exit;
    }

    // 1. Obtener información de la reserva
    $query = "SELECT r.id, r.fecha, r.hora_inicio, r.estado FROM reservas r ";
    $query .= "JOIN reserva_jugadores rj ON r.id = rj.reserva_id ";
    $query .= "WHERE r.id = ? AND rj.jugador_id = ?";
    
    $stmtReserva = $conn->prepare($query);
    
    if (!$stmtReserva) {
        throw new Exception("Error prepare: " . $conn->error);
    }
    
    $stmtReserva->bind_param("ii", $reserva_id, $jugador_id);
    
    if (!$stmtReserva->execute()) {
        throw new Exception("Error execute: " . $stmtReserva->error);
    }
    
    $result = $stmtReserva->get_result();
    $reserva = $result->fetch_assoc();
    
    if (!$reserva) {
        http_response_code(404);
        echo json_encode(["error" => "Reserva no encontrada"]);
        exit;
    }
    
    // 2. Validar que no esté cancelada
    if ($reserva['estado'] === 'cancelado') {
        http_response_code(400);
        echo json_encode(["error" => "Esta reserva ya fue cancelada"]);
        exit;
    }
    
    // 3. Validar las 12 horas de anticipación
    $fecha_hora_reserva = new DateTime($reserva['fecha'] . ' ' . $reserva['hora_inicio']);
    $ahora = new DateTime();
    
    // Calcular diferencia en segundos y convertir a horas
    $timestamp_reserva = $fecha_hora_reserva->getTimestamp();
    $timestamp_ahora = $ahora->getTimestamp();
    $diferencia_segundos = $timestamp_reserva - $timestamp_ahora;
    $horas_restantes = $diferencia_segundos / 3600;
    
    if ($horas_restantes < 8) {
        http_response_code(400);
        echo json_encode([
            "error" => "No puedes cancelar con menos de 8 horas de anticipación",
            "horas_restantes" => max(0, floor($horas_restantes)),
            "code" => "INSUFFICIENT_TIME"
        ]);
        exit;
    }
    
    // 4. Cancelar la reserva
    $stmtCancel = $conn->prepare("UPDATE reservas SET estado = 'cancelado' WHERE id = ?");
    if (!$stmtCancel) {
        throw new Exception("Error prepare cancel: " . $conn->error);
    }
    
    $stmtCancel->bind_param("i", $reserva_id);
    
    if (!$stmtCancel->execute()) {
        throw new Exception("Error execute cancel: " . $stmtCancel->error);
    }

    // --- SEND RESPONSE IMMEDIATELY ---
    echo json_encode([
        "ok" => true,
        "message" => "Reserva cancelada correctamente",
        "reserva_id" => $reserva_id
    ]);

    // Flush and close connection if possible
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    // --- NOTIFICATIONS START (Background Processing) ---
    require_once "../notifications/whatsapp_service.php";
    require_once "../system/mail_service.php";
    require_once "../notifications/notificaciones_helper.php";
    
    // Fetch phones, emails AND names
    $sqlData = "
        SELECT 
            u1.id as jugador_id, u1.telefono as cel_jugador, u1.nombre as nom_jugador, u1.usuario as email_jugador,
            u2.id as entrenador_id, u2.telefono as cel_entrenador, u2.nombre as nom_entrenador, u2.usuario as email_entrenador
        FROM usuarios u1 
        JOIN reservas r ON r.id = ?
        JOIN usuarios u2 ON u2.id = r.entrenador_id
        WHERE u1.id = ?
    ";

    $stmtP = $conn->prepare($sqlData);
    if ($stmtP) {
        $stmtP->bind_param("ii", $reserva_id, $jugador_id);
        $stmtP->execute();
        $resP = $stmtP->get_result()->fetch_assoc();
        
        if ($resP) {
            $nomJugador = $resP['nom_jugador'];
            $nomEntrenador = $resP['nom_entrenador'];
            $emailJugador = $resP['email_jugador'];
            $emailEntrenador = $resP['email_entrenador'];
            
            $fechaFmt = date("d/m/Y", strtotime($reserva['fecha']));
            $horaFmt = substr($reserva['hora_inicio'], 0, 5);

            // 1. WHATSAPP
            $vars = [$fechaFmt, $horaFmt, $nomJugador, $nomEntrenador];
            if ($resP['cel_jugador']) enviarWhatsApp($resP['cel_jugador'], 'reserva_cancelada', 'es_CL', $vars); 
            if ($resP['cel_entrenador']) enviarWhatsApp($resP['cel_entrenador'], 'reserva_cancelada', 'es_CL', $vars);

            // 2. EMAIL
            $subject = "🚫 Reserva Cancelada - " . $fechaFmt . " " . $horaFmt;
            $bodyPlayer = "<div style='font-family: Arial, sans-serif;'><h2>🚫 Reserva Cancelada</h2><p>Hola <strong>$nomJugador</strong>, tu reserva para el entrenamiento con <strong>$nomEntrenador</strong> ha sido cancelada.</p></div>";
            $bodyCoach = "<div style='font-family: Arial, sans-serif;'><h2>🚫 Entrenamiento cancelado</h2><p>Hola <strong>$nomEntrenador</strong>, el jugador <strong>$nomJugador</strong> ha cancelado su asistencia.</p></div>";

            if (!empty($emailJugador)) enviarCorreoSMTP($emailJugador, $subject, $bodyPlayer);
            if (!empty($emailEntrenador)) enviarCorreoSMTP($emailEntrenador, $subject, $bodyCoach);

            // 3. PUSH
            $idEntrenador = $resP['entrenador_id'] ?? $reserva['entrenador_id'];
            notifyUser($conn, $idEntrenador, "🚫 Clase Cancelada por Alumno", "$nomJugador ha cancelado la clase del $fechaFmt a las $horaFmt", 'clase_cancelada');
            notifyUser($conn, $jugador_id, "🚫 Cancelación Confirmada", "Has cancelado tu clase del $fechaFmt a las $horaFmt.", 'cancelacion_confirmada');
        }
    }
    // --- NOTIFICATIONS END ---

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}

if (isset($conn)) {
    $conn->close();
}
?>
