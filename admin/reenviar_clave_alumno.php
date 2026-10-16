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
require_once "../system/mail_service.php";

$email = $_GET['email'] ?? $_POST['email'] ?? '';

if (empty($email)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "El parámetro 'email' es obligatorio"]);
    exit;
}

// 1. Buscar al usuario en la base de datos
$sql = "SELECT id, nombre, usuario, rol FROM usuarios WHERE usuario = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    http_response_code(404);
    echo json_encode(["success" => false, "error" => "No se encontró ningún usuario registrado con el correo: $email"]);
    exit;
}

$nombre = $user['nombre'];
$rol = $user['rol'];

// 2. Generar nueva contraseña temporal
$randomPass = "Padel" . rand(10000, 99999);
$passwordHash = password_hash($randomPass, PASSWORD_DEFAULT);

// 3. Actualizar la contraseña en la base de datos
$sqlUpd = "UPDATE usuarios SET password = ? WHERE id = ?";
$stmtUpd = $conn->prepare($sqlUpd);
$stmtUpd->bind_param("si", $passwordHash, $user['id']);

if ($stmtUpd->execute()) {
    // 4. Construir y enviar el correo de bienvenida con las nuevas credenciales
    $subject = "🎾 Tus credenciales de acceso - PadelBlox";
    $bodyHTML = "
    <html>
    <body style='margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif;'>
      <table width='100%' border='0' cellspacing='0' cellpadding='0' style='background-color: #f1f5f9; padding: 40px 20px;'>
        <tr>
          <td align='center'>
            <div style='max-width: 500px; width: 100%; background-color: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: 1px solid #e2e8f0;'>
              <div style='background-color: #ccff00; padding: 40px; text-align: center;'>
                <img src='https://api.padelmanager.cl/assets/images/logo-padelblox.png' alt='PadelBlox' style='width: 120px; height: 120px; border-radius: 20px;' />
                <h1 style='margin: 20px 0 0; color: #000; font-size: 28px; font-weight: 900; text-transform: uppercase; letter-spacing: -1px;'>PadelBlox</h1>
              </div>
              <div style='padding: 40px 30px; text-align: left; color: #1e293b;'>
                <h2 style='margin: 0 0 20px; font-size: 22px; font-weight: 800; color: #000;'>¡Hola, " . htmlspecialchars($nombre) . "!</h2>
                <p style='margin: 0 0 20px; font-size: 16px; color: #475569; line-height: 1.6;'>
                  Reenviamos tus credenciales de acceso para que puedas ingresar a <strong>PadelBlox</strong>, gestionar tus entrenamientos y ver tu evolución.
                </p>
                
                <div style='background-color: #f8fafc; border-left: 5px solid #ccff00; padding: 25px; margin-bottom: 30px; border-radius: 8px;'>
                  <p style='margin: 0 0 15px; font-size: 14px; color: #64748b; text-transform: uppercase; font-weight: 800; letter-spacing: 0.5px;'>Tus credenciales de acceso</p>
                  <p style='margin: 0 0 10px; font-size: 16px; font-weight: 700;'>Usuario: <span style='color: #2563eb;'>" . htmlspecialchars($email) . "</span></p>
                  <p style='margin: 0; font-size: 16px; font-weight: 700;'>Contraseña: <span style='font-family: monospace; background: #e2e8f0; padding: 4px 8px; border-radius: 4px; font-size: 18px;'>$randomPass</span></p>
                </div>

                <div style='text-align: center; margin-bottom: 30px;'>
                  <a href='https://www.padelblox.cl' style='display: inline-block; background-color: #000; color: #ccff00; padding: 18px 40px; text-decoration: none; font-weight: 900; font-size: 16px; border-radius: 12px; box-shadow: 0 10px 20px rgba(0,0,0,0.1);'>INGRESAR A LA APP</a>
                </div>

                <p style='margin: 0; font-size: 14px; color: #94a3b8; text-align: center;'>
                  Te recomendamos cambiar esta contraseña en la configuración de tu perfil al ingresar.
                </p>
              </div>
              <div style='background-color: #f8fafc; padding: 25px; text-align: center; border-top: 1px solid #e2e8f0;'>
                <p style='margin: 0; font-size: 12px; color: #94a3b8;'>
                  &copy; " . date('Y') . " PadelBlox. Todos los derechos reservados.
                </p>
              </div>
            </div>
          </td>
        </tr>
      </table>
    </body>
    </html>";

    $mailResult = enviarCorreoSMTP($email, $subject, $bodyHTML);

    if ($mailResult['success']) {
        echo json_encode([
            "success" => true,
            "message" => "Nueva clave generada y correo de bienvenida enviado con éxito a $email",
            "temp_password" => $randomPass
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "error" => "Se actualizó la contraseña en la base de datos pero falló el envío de correo: " . $mailResult['error']
        ]);
    }
} else {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Error de base de datos al actualizar la contraseña"]);
}

$conn->close();
?>
