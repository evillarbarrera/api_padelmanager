<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$headers = getallheaders();
$auth = $headers['Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

if ($auth !== 'Bearer ' . base64_encode("1|padel_academy")) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

try {
    require_once "../db.php";

    $data = json_decode(file_get_contents("php://input"), true);
    $reserva_id = $data['reserva_id'] ?? null;

    if (!$reserva_id) {
        http_response_code(400);
        echo json_encode(["error" => "reserva_id es obligatorio"]);
        exit;
    }

    // 1. Obtener información de la reserva
    $stmtInf = $conn->prepare("SELECT fecha, hora_inicio, entrenador_id, estado FROM reservas WHERE id = ?");
    $stmtInf->bind_param("i", $reserva_id);
    $stmtInf->execute();
    $reserva = $stmtInf->get_result()->fetch_assoc();

    if (!$reserva) {
        http_response_code(404);
        echo json_encode(["error" => "Reserva no encontrada"]);
        exit;
    }

    if ($reserva['estado'] === 'cancelado') {
        echo json_encode(["ok" => true, "message" => "La reserva ya estaba cancelada"]);
        exit;
    }

    // 2. Cancelar la reserva
    $sql = "UPDATE reservas SET estado = 'cancelado' WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $reserva_id);

    if ($stmt->execute()) {
        // --- RESPUESTA INMEDIATA ---
        echo json_encode(["ok" => true, "message" => "Reserva cancelada correctamente"]);
        
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // --- NOTIFICATIONS START ---
        require_once "../notifications/whatsapp_service.php";
        require_once "../system/mail_service.php";

        $fechaFmt = date("d/m/Y", strtotime($reserva['fecha']));
        $horaFmt = substr($reserva['hora_inicio'], 0, 5);
        $entrenador_id = $reserva['entrenador_id'];

        // Obtener datos del entrenador
        $stmtE = $conn->prepare("SELECT nombre, telefono, usuario FROM usuarios WHERE id = ?");
        $stmtE->bind_param("i", $entrenador_id);
        $stmtE->execute();
        $entData = $stmtE->get_result()->fetch_assoc();
        
        $nomEntrenador = $entData['nombre'] ?? 'Tu Entrenador';
        $celEntrenador = $entData['telefono'] ?? null;
        $emailEntrenador = $entData['usuario'] ?? null;

        // Obtener todos los jugadores inscritos en esta reserva
        $sqlJ = "
            SELECT u.id, u.nombre, u.telefono, u.usuario 
            FROM usuarios u
            JOIN reserva_jugadores rj ON u.id = rj.jugador_id
            WHERE rj.reserva_id = ?
        ";
        $stmtJ = $conn->prepare($sqlJ);
        $stmtJ->bind_param("i", $reserva_id);
        $stmtJ->execute();
        $resultJ = $stmtJ->get_result();

        $subject = "🚫 Clase Cancelada por el Entrenador - $fechaFmt $horaFmt";

        while ($jugador = $resultJ->fetch_assoc()) {
            $nomJugador = $jugador['nombre'];
            $celJugador = $jugador['telefono'];
            $emailJugador = $jugador['usuario'];
            $jugador_id = $jugador['id'];

            // 1. WhatsApp
            $vars = [$fechaFmt, $horaFmt, $nomJugador, $nomEntrenador];
            if ($celJugador) enviarWhatsApp($celJugador, 'reserva_cancelada', 'es_CL', $vars);

            // 2. Email
            $bodyPlayer = "
            <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                <h2 style='color: #d32f2f;'>🚫 Clase Cancelada</h2>
                <p>Hola <strong>$nomJugador</strong>,</p>
                <p>Lamentamos informarte que tu entrenador <strong>$nomEntrenador</strong> ha tenido que cancelar la clase programada.</p>
                <div style='background: #f4f4f4; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                    <p style='margin: 5px 0;'><strong>Fecha:</strong> $fechaFmt</p>
                    <p style='margin: 5px 0;'><strong>Hora:</strong> $horaFmt</p>
                </div>
                <p>La sesión ha sido devuelta a tu pack automáticamente.</p>
                <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
                <p style='font-size: 12px; color: #888;'>Padel Manager</p>
            </div>";
            if (!empty($emailJugador)) enviarCorreoSMTP($emailJugador, $subject, $bodyPlayer);

            // 3. Push (Save to DB)
            $stmtNotif = $conn->prepare("INSERT INTO notificaciones (user_id, titulo, mensaje, tipo, leida) VALUES (?, ?, ?, 'clase_cancelada', 0)");
            $tituloPush = "🚫 Clase Cancelada";
            $mensajePush = "$nomEntrenador ha cancelado la clase del $fechaFmt a las $horaFmt";
            $stmtNotif->bind_param("iss", $jugador_id, $tituloPush, $mensajePush);
            $stmtNotif->execute();
            $stmtNotif->close();
        }

        // También notificar al entrenador por email/WhatsApp si se desea (opcional confirmación)
        if ($emailEntrenador) {
            $bodyCoach = "
            <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                <h2 style='color: #111;'>✅ Clase Cancelada Correctamente</h2>
                <p>Hola <strong>$nomEntrenador</strong>,</p>
                <p>Has cancelado la clase para el <strong>$fechaFmt</strong> a las <strong>$horaFmt</strong>.</p>
                <p>Se ha notificado a todos los jugadores inscritos y el cupo ha quedado libre en tu agenda.</p>
            </div>";
            enviarCorreoSMTP($emailEntrenador, "Confirmación de Cancelación - $fechaFmt", $bodyCoach);
        }
        
        if ($celEntrenador) {
            enviarWhatsApp($celEntrenador, 'reserva_cancelada', 'es_CL', [$fechaFmt, $horaFmt, 'Todos los alumnos', $nomEntrenador]);
        }

    } else {
        http_response_code(500);
        echo json_encode(["error" => "Error al cancelar la reserva: " . $conn->error]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}

$conn->close();
