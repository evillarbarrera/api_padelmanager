<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$email = trim($data['email'] ?? '');

if (empty($email)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "El correo electrónico es obligatorio"]);
    exit;
}

// Búsqueda insensible a mayúsculas/minúsculas para el email
$sql = "SELECT id, nombre, usuario FROM usuarios WHERE LOWER(TRIM(usuario)) = LOWER(?) LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

$mail_sent = false;
$resMail = null;

if ($user = $result->fetch_assoc()) {
    require_once "../system/mail_service.php";
    
    // 1. Generar token único de 1 hora de validez
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $user_id = $user['id'];

    // 2. Guardar token en BD
    try {
        $stmtToken = $conn->prepare("UPDATE usuarios SET reset_token = ?, reset_expires = ? WHERE id = ?");
        if ($stmtToken) {
            $stmtToken->bind_param("ssi", $token, $expires, $user_id);
            $stmtToken->execute();
            $stmtToken->close();
        }
    } catch (Throwable $eToken) {
        error_log("Error actualizando reset_token: " . $eToken->getMessage());
    }

    // 3. Preparar Link (Apunta al frontend - Versión con Hash para asegurar compatibilidad en todos los servers)
    $resetLink = "https://padelblox.cl/#/reset-password?token=" . $token;
    
    $to = $user['usuario'] ?: $email;
    $subject = "Restablecer tu contraseña - PadelBlox";
    $nombreUser = !empty($user['nombre']) ? htmlspecialchars($user['nombre']) : 'Jugador';
    
    $message = "
    <!DOCTYPE html>
    <html>
    <head>
      <meta charset='UTF-8'>
      <title>Restablecer Contraseña - PadelBlox</title>
    </head>
    <body style='margin: 0; padding: 0; background-color: #080C14; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; color: #FFFFFF;'>
      <table width='100%' border='0' cellspacing='0' cellpadding='0' style='background-color: #080C14; padding: 40px 15px;'>
        <tr>
          <td align='center'>
            <div style='max-width: 560px; background: #0E131F; border: 1px solid #1E293B; border-radius: 20px; overflow: hidden; box-shadow: 0 20px 40px rgba(0,0,0,0.6);'>
              <!-- Header con Logo -->
              <div style='background: #080C14; padding: 30px 20px; text-align: center; border-bottom: 1px solid #1E293B;'>
                <div style='display: inline-block; background: #161D2E; border: 1px solid #2A364F; border-radius: 12px; padding: 8px 18px;'>
                  <span style='color: #CCFF00; font-size: 16px; font-weight: 900; letter-spacing: 2px;'>🎾 PADELBLOX</span>
                </div>
              </div>

              <!-- Contenido Principal -->
              <div style='padding: 35px 30px; text-align: left;'>
                <h1 style='color: #FFFFFF; font-size: 22px; font-weight: 900; margin: 0 0 15px; letter-spacing: -0.5px;'>
                  Recuperación de Contraseña
                </h1>
                <p style='color: #94A3B8; font-size: 15px; line-height: 1.6; margin: 0 0 20px;'>
                  Hola <strong style='color: #FFFFFF;'>$nombreUser</strong>,
                </p>
                <p style='color: #94A3B8; font-size: 14px; line-height: 1.6; margin: 0 0 30px;'>
                  Recibimos una solicitud para restablecer la contraseña de tu cuenta de PadelBlox. Para crear una nueva contraseña, haz clic en el siguiente botón:
                </p>
                
                <!-- Botón CTA Volt -->
                <div style='text-align: center; margin: 35px 0;'>
                  <a href='$resetLink' style='background-color: #CCFF00; color: #080C14; padding: 15px 35px; text-decoration: none; font-weight: 900; font-size: 14px; letter-spacing: 0.5px; border-radius: 12px; display: inline-block; box-shadow: 0 6px 20px rgba(204, 255, 0, 0.3);'>
                    RESTABLECER MI CONTRASEÑA
                  </a>
                </div>

                <div style='background: rgba(204, 255, 0, 0.05); border: 1px dashed rgba(204, 255, 0, 0.3); border-radius: 12px; padding: 14px; margin: 25px 0;'>
                  <p style='color: #E2E8F0; font-size: 12.5px; margin: 0; line-height: 1.5;'>
                    ⏱️ Por seguridad, este enlace tiene una validez de <strong>1 hora</strong>. Si no solicitaste este cambio, puedes ignorar este correo; tu contraseña actual continuará siendo segura.
                  </p>
                </div>
                
                <p style='font-size: 12px; color: #64748B; border-top: 1px solid #1E293B; margin-top: 30px; padding-top: 15px; word-break: break-all;'>
                  Si el botón no funciona, copia y pega este enlace en tu navegador:<br>
                  <a href='$resetLink' style='color: #38BDF8; text-decoration: none;'>$resetLink</a>
                </p>
              </div>

              <!-- Footer -->
              <div style='background: #080C14; padding: 20px; text-align: center; border-top: 1px solid #1E293B;'>
                <p style='color: #475569; font-size: 11.5px; margin: 0;'>
                  © " . date('Y') . " PadelBlox. Todos los derechos reservados.
                </p>
              </div>
            </div>
          </td>
        </tr>
      </table>
    </body>
    </html>
    ";
    
    $resMail = enviarCorreoSMTP($to, $subject, $message);
    $mail_sent = !empty($resMail['success']);
}

// Retornamos respuesta JSON
echo json_encode([
    "success" => true,
    "mail_sent" => $mail_sent,
    "internal_debug" => isset($user) 
        ? ($mail_sent ? "Sent successfully" : "Delivery failed: " . ($resMail['error'] ?? 'Unknown error'))
        : "UserNotFound",
    "message" => "Si el correo está registrado, recibirás instrucciones en unos minutos."
]);
