<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$email = $data['email'] ?? '';

if (empty($email)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "El correo electrónico es obligatorio"]);
    exit;
}

// Check if user exists
// Note: In register.php, 'usuario' column stores the email
$sql = "SELECT id, nombre, usuario FROM usuarios WHERE usuario = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($user = $result->fetch_assoc()) {
    require_once "../system/mail_service.php";
    
    // 1. Generar token único de 1 hora de validez
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $user_id = $user['id'];

    // 2. Guardar token en BD
    $stmtToken = $conn->prepare("UPDATE usuarios SET reset_token = ?, reset_expires = ? WHERE id = ?");
    $stmtToken->bind_param("ssi", $token, $expires, $user_id);
    $stmtToken->execute();

    // 3. Preparar Link (Apunta al frontend - Versión con Hash para asegurar compatibilidad en todos los servers)
    $resetLink = "https://padelmanager.cl/#/reset-password?token=" . $token;
    
    $to = $email;
    $subject = "NUEVO: Restablecer tu contraseña - Padel Manager";
    
    $message = "
    <html>
    <head>
      <title>Restablecer Contraseña</title>
    </head>
    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
      <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
        <h2 style='color: #111; border-bottom: 2px solid #ccff00; padding-bottom: 10px;'>🎾 PADEL MANAGER</h2>
        <p>Hola <strong>" . htmlspecialchars($user['nombre']) . "</strong>,</p>
        <p>Hemos recibido una solicitud para restablecer tu contraseña. Haz clic en el botón de abajo para elegir una nueva:</p>
        
        <div style='text-align: center; margin: 30px 0;'>
          <a href='$resetLink' style='background-color: #ccff00; color: #000; padding: 12px 25px; text-decoration: none; font-weight: bold; border-radius: 5px; display: inline-block;'>RESTABLECER CONTRASEÑA</a>
        </div>

        <p>Este enlace expirará en 1 hora por seguridad.</p>
        <p>Si no realizaste esta solicitud, puedes ignorar este correo.</p>
        
        <p style='font-size: 12px; color: #888; border-top: 1px solid #eee; margin-top: 30px; padding-top: 10px;'>
          Si el botón no funciona, copia y pega este link en tu navegador:<br>
          <span style='color: #0066cc;'>$resetLink</span>
        </p>
      </div>
    </body>
    </html>
    ";
    
    $resMail = enviarCorreoSMTP($to, $subject, $message);
    $mail_sent = $resMail['success'];
}

// Siempre retornamos true al cliente por seguridad
echo json_encode([
    "success" => true,
    "internal_debug" => isset($mail_sent) ? ($mail_sent ? "Sent via SMTP" : "Failed SMTP: " . ($resMail['error'] ?? 'Unknown')) : "UserNotFound",
    "message" => "Si el correo está registrado, recibirás instrucciones en unos minutos."
]);
