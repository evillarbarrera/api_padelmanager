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
            <html>
            <body style='margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica, Arial, sans-serif;'>
              <table width='100%' border='0' cellspacing='0' cellpadding='0' style='background-color: #f1f5f9; padding: 40px 20px;'>
                <tr><td align='center'>
                  <div style='max-width: 500px; width: 100%; background-color: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: 1px solid #e2e8f0;'>
                    <div style='background-color: #ccff00; padding: 40px; text-align: center; color: #000000;'>
                      <img src='https://api.padelmanager.cl/assets/images/logo-padelblox.png' alt='PadelBlox' style='width: 120px; height: 120px; border-radius: 20px;' />
                      <h1 style='margin: 20px 0 0; font-size: 26px; font-weight: 900; text-transform: uppercase;'>¡CLASE AGENDADA!</h1>
                      <p style='margin: 5px 0 0; font-size: 14px; font-weight: 700; opacity: 0.8;'>Tu entrenador te ha agendado una clase.</p>
                    </div>
                    <div style='padding: 40px 30px; text-align: left; color: #1e293b;'>
                      <h2 style='margin: 0 0 20px; font-size: 20px; font-weight: 800; color: #000;'>¡Hola, $nomJugador!</h2>
                      <div style='background-color: #f8fafc; border-left: 5px solid #ccff00; padding: 25px; margin-bottom: 30px; border-radius: 8px;'>
                        <p style='margin: 0 0 12px; font-size: 15px;'><strong>Entrenador:</strong> $nomEntrenador</p>
                        <p style='margin: 0 0 12px; font-size: 15px;'><strong>Fecha:</strong> $fechaFmt</p>
                        <p style='margin: 0; font-size: 15px;'><strong>Hora:</strong> $horaFmt</p>
                      </div>
                      <p style='font-size: 15px; color: #475569;'>¡Nos vemos en la pista!</p>
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
            enviarCorreoSMTP($emailJugador, $subject, $body);
        }

        // NOTIFICAR AL ENTRENADOR
        $msgEntr = "Has agregado manualmente a " . ($uAlum['nombre'] ?? 'un alumno') . " a la clase del $fechaFmt a las $horaFmt.";
        notifyUser($conn, $entrenador_id_auth, "🎾 Alumno Agregado", $msgEntr, 'nueva_reserva');

        $stmtE = $conn->prepare("SELECT nombre, usuario FROM usuarios WHERE id = ?");
        $stmtE->bind_param("i", $entrenador_id_auth);
        $stmtE->execute();
        $uEntr = $stmtE->get_result()->fetch_assoc();

        if ($uEntr && !empty($uEntr['usuario'])) {
            $nomEntrenador = $uEntr['nombre'];
            $emailEntrenador = $uEntr['usuario'];
            $subjectEntr = "🎾 Nuevo Alumno Agregado - $fechaFmt $horaFmt";
            $bodyEntr = "
            <html>
            <body style='margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica, Arial, sans-serif;'>
              <table width='100%' border='0' cellspacing='0' cellpadding='0' style='background-color: #f1f5f9; padding: 40px 20px;'>
                <tr><td align='center'>
                  <div style='max-width: 500px; width: 100%; background-color: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: 1px solid #e2e8f0;'>
                    <div style='background-color: #ccff00; padding: 40px; text-align: center; color: #000000;'>
                      <img src='https://api.padelmanager.cl/assets/images/logo-padelblox.png' alt='PadelBlox' style='width: 120px; height: 120px; border-radius: 20px;' />
                      <h1 style='margin: 20px 0 0; font-size: 26px; font-weight: 900; text-transform: uppercase;'>ALUMNO AGREGADO</h1>
                      <p style='margin: 5px 0 0; font-size: 14px; font-weight: 700; opacity: 0.8;'>Se ha agregado exitosamente un alumno a tu clase.</p>
                    </div>
                    <div style='padding: 40px 30px; text-align: left; color: #1e293b;'>
                      <h2 style='margin: 0 0 20px; font-size: 20px; font-weight: 800; color: #000;'>¡Hola, $nomEntrenador!</h2>
                      <div style='background-color: #f8fafc; border-left: 5px solid #ccff00; padding: 25px; margin-bottom: 30px; border-radius: 8px;'>
                        <p style='margin: 0 0 12px; font-size: 15px;'><strong>Alumno:</strong> " . ($uAlum['nombre'] ?? 'Alumno') . "</p>
                        <p style='margin: 0 0 12px; font-size: 15px;'><strong>Fecha:</strong> $fechaFmt</p>
                        <p style='margin: 0; font-size: 15px;'><strong>Hora:</strong> $horaFmt</p>
                      </div>
                      <p style='font-size: 15px; color: #475569;'>¡Excelente trabajo!</p>
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
            enviarCorreoSMTP($emailEntrenador, $subjectEntr, $bodyEntr);
        }
    }
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al agregar: " . $conn->error]);
}
?>
