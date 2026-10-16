<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
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
            <html>
            <body style='margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica, Arial, sans-serif;'>
              <table width='100%' border='0' cellspacing='0' cellpadding='0' style='background-color: #f1f5f9; padding: 40px 20px;'>
                <tr><td align='center'>
                  <div style='max-width: 500px; width: 100%; background-color: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: 1px solid #e2e8f0;'>
                    <div style='background-color: #ef4444; padding: 40px; text-align: center; color: #ffffff;'>
                      <img src='https://api.padelmanager.cl/assets/images/logo-padelblox.png' alt='PadelBlox' style='width: 120px; height: 120px; border-radius: 20px;' />
                      <h1 style='margin: 20px 0 0; font-size: 26px; font-weight: 900; text-transform: uppercase;'>CLASE CANCELADA</h1>
                      <p style='margin: 5px 0 0; font-size: 14px; font-weight: 700; opacity: 0.8;'>Tu entrenador ha cancelado la clase.</p>
                    </div>
                    <div style='padding: 40px 30px; text-align: left; color: #1e293b;'>
                      <h2 style='margin: 0 0 20px; font-size: 20px; font-weight: 800; color: #000;'>Hola, $nomJugador</h2>
                      <p style='font-size: 15px; color: #475569; margin-bottom: 20px;'>Lamentamos informarte que tu entrenador <strong>$nomEntrenador</strong> ha tenido que cancelar la clase programada.</p>
                      <div style='background-color: #f8fafc; border-left: 5px solid #ef4444; padding: 25px; margin-bottom: 30px; border-radius: 8px;'>
                        <p style='margin: 0 0 12px; font-size: 15px;'><strong>Fecha:</strong> $fechaFmt</p>
                        <p style='margin: 0; font-size: 15px;'><strong>Hora:</strong> $horaFmt</p>
                      </div>
                      <p style='font-size: 15px; color: #475569;'>La sesión ha sido devuelta a tu pack automáticamente.</p>
                      <div style='text-align: center; margin-bottom: 20px;'>
                        <a href='https://www.padelblox.cl' style='display: inline-block; background-color: #000; color: #ccff00; padding: 18px 40px; text-decoration: none; font-weight: 900; font-size: 16px; border-radius: 12px;'>INGRESAR A LA APP</a>
                      </div>
                    </div>
                    <div style='background-color: #f8fafc; padding: 25px; text-align: center; border-top: 1px solid #e2e8f0;'>
                      <p style='margin: 0; font-size: 12px; color: #94a3b8;'>&copy; " . date('Y') . " PadelBlox. Todos los derechos reservados.</p>
                    </div>
                  </div>
                </td></tr>
              </table>
            </body>
            </html>";
            if (!empty($emailJugador)) enviarCorreoSMTP($emailJugador, $subject, $bodyPlayer);

            // 3. Push Notifications
            require_once "../notifications/notificaciones_helper.php";
            $tituloPush = "🚫 Clase Cancelada";
            $mensajePush = "$nomEntrenador ha cancelado la clase del $fechaFmt a las $horaFmt";
            notifyUser($conn, $jugador_id, $tituloPush, $mensajePush, 'clase_cancelada');
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
