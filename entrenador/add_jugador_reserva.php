<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../auth/auth_helper.php";
$entrenador_id_auth = validateToken();
if (!$entrenador_id_auth) {
    sendUnauthorized();
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    http_response_code(400);
    echo json_encode(["error" => "Datos inválidos"]);
    exit;
}

$reserva_id = $data['reserva_id'] ?? 0;
$jugador_id = $data['jugador_id'] ?? 0;
$pack_id = $data['pack_id'] ?? 0;

if (!$reserva_id || !$jugador_id) {
    http_response_code(400);
    echo json_encode(["error" => "reserva_id y jugador_id son requeridos"]);
    exit;
}

// 1. Verificar que la reserva existe y pertenece al entrenador
$sql_check = "SELECT id, pack_id FROM reservas WHERE id = ? AND entrenador_id = ?";
$stmt = $conn->prepare($sql_check);
$stmt->bind_param("ii", $reserva_id, $entrenador_id_auth);
$stmt->execute();
$reserva = $stmt->get_result()->fetch_assoc();

if (!$reserva) {
    http_response_code(403);
    echo json_encode(["error" => "No tienes permiso para modificar esta reserva"]);
    exit;
}

// 2. Verificar si el jugador ya está en esa sesión
$sql_check_rj = "SELECT id FROM reserva_jugadores WHERE reserva_id = ? AND jugador_id = ?";
$stmt = $conn->prepare($sql_check_rj);
$stmt->bind_param("ii", $reserva_id, $jugador_id);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) {
    http_response_code(400);
    echo json_encode(["error" => "El jugador ya está en esta clase"]);
    exit;
}

// 3. Insertar en reserva_jugadores
$sql_insert = "INSERT INTO reserva_jugadores (reserva_id, jugador_id) VALUES (?, ?)";
$stmt = $conn->prepare($sql_insert);
$stmt->bind_param("ii", $reserva_id, $jugador_id);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Jugador agregado a la sesión"]);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    // NOTIFICAR AL ALUMNO
    require_once "../notifications/notificaciones_helper.php";
    
    // Obtener detalles para el mensaje
    $sql_info = "
        SELECT r.fecha, r.hora_inicio, u.nombre as entrenador_nombre
        FROM reservas r
        JOIN usuarios u ON u.id = r.entrenador_id
        WHERE r.id = ?
    ";
    $stmt_info = $conn->prepare($sql_info);
    $stmt_info->bind_param("i", $reserva_id);
    $stmt_info->execute();
    $info = $stmt_info->get_result()->fetch_assoc();

    if ($info) {
        $fechaFmt = date("d/m/Y", strtotime($info['fecha']));
        $horaFmt = substr($info['hora_inicio'], 0, 5);
        $nomEntrenador = $info['entrenador_nombre'];
        
        $msg = "Has sido agregado a la clase con $nomEntrenador el $fechaFmt a las $horaFmt.";
        notifyUser($conn, $jugador_id, "🎾 Nueva Clase Agendada", $msg, 'clase_agendada');

        // ENVIAR CORREO
        require_once "../system/mail_service.php";
        $stmtU = $conn->prepare("SELECT nombre, usuario FROM usuarios WHERE id = ?");
        $stmtU->bind_param("i", $jugador_id);
        $stmtU->execute();
        $uAlum = $stmtU->get_result()->fetch_assoc();

        if ($uAlum && !empty($uAlum['usuario'])) {
            $nomJugador = $uAlum['nombre'];
            $emailJugador = $uAlum['usuario'];
            $subject = "🎾 Nueva Clase Agendada - $fechaFmt $horaFmt";
            $body = "
            <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                <h2 style='color: #1a73e8;'>¡Te han agregado a una clase!</h2>
                <p>Hola <strong>$nomJugador</strong>,</p>
                <p>Tu entrenador te ha agendado una nueva clase de pádel:</p>
                <ul>
                    <li><strong>Entrenador:</strong> $nomEntrenador</li>
                    <li><strong>Fecha:</strong> $fechaFmt</li>
                    <li><strong>Hora:</strong> $horaFmt</li>
                </ul>
                <p>¡Nos vemos en la pista!</p>
                <hr style='border: 0; border-top: 1px solid #eee;'>
                <p style='font-size: 12px; color: #777;'>Padel Manager - Academia</p>
            </div>";
            enviarCorreoSMTP($emailJugador, $subject, $body);
        }
    }
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al agregar: " . $conn->error]);
}
?>
