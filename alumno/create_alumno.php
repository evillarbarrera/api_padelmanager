<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Content-Type: application/json");

// AGREGAR ESTO: Manejador para Pre-vuelo (OPTIONS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../auth/auth_helper.php";
$userId = validateToken();
if (!$userId) {
    sendUnauthorized();
}

require_once "../db.php";
require_once "../system/mail_service.php";

// 🏆 CAPTURA ROBUSTA DE DATOS (JSON o POST)
$inputJSON = file_get_contents("php://input");
$data = json_decode($inputJSON, true) ?? [];

$nombre = $data['nombre'] ?? $_POST['nombre'] ?? '';
$email = $data['email'] ?? $_POST['email'] ?? '';

// 🧠 AUTOCORRECCIÓN: Si el nombre contiene una @ y el email no tiene @ (o está vacío), los intercambiamos.
if (strpos($nombre, '@') !== false && strpos($email, '@') === false) {
    $temp = $nombre;
    $nombre = $email;
    $email = $temp;
}
$entrenador_id = $data['entrenador_id'] ?? $_POST['entrenador_id'] ?? 0;

// Validar campos obligatorios con Debug
if (empty($nombre) || empty($email) || empty($entrenador_id)) {
    http_response_code(400);
    echo json_encode([
        "success" => false, 
        "error" => "Faltan datos obligatorios",
        "details" => [
            "nombre_ok" => !empty($nombre),
            "email_ok" => !empty($email),
            "coach_ok" => !empty($entrenador_id)
        ]
    ]);
    exit;
}

// 🛠️ SILENT SCHEMA FIX
function ensureColumnUsers($conn, $column, $definition) {
    $check = $conn->query("SHOW COLUMNS FROM usuarios LIKE '$column'");
    if ($check && $check->num_rows == 0) {
        $conn->query("ALTER TABLE usuarios ADD `$column` $definition");
    }
}
ensureColumnUsers($conn, 'entrenador_creador_id', "INT NULL DEFAULT NULL AFTER `rol` ");

// Ensure bridge table exists
$conn->query("CREATE TABLE IF NOT EXISTS entrenador_alumno (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entrenador_id INT NOT NULL,
    alumno_id INT NOT NULL,
    fecha_asociacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    creado_por_entrenador TINYINT(1) DEFAULT 0,
    UNIQUE KEY unique_rel (entrenador_id, alumno_id)
)");

// 1. Verificar si el usuario ya existe
$sqlCheck = "SELECT id, nombre, usuario, entrenador_creador_id FROM usuarios WHERE usuario = ?";
$stmtCheck = $conn->prepare($sqlCheck);
$stmtCheck->bind_param("s", $email);
$stmtCheck->execute();
$resultCheck = $stmtCheck->get_result();
$existingUser = $resultCheck->fetch_assoc();

if ($existingUser) {
    $existingId = $existingUser['id'];
    
    // Si ya existe, simplemente creamos la relación con este entrenador
    $sqlRel = "INSERT INTO entrenador_alumno (entrenador_id, alumno_id, creado_por_entrenador) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE entrenador_id = entrenador_id";
    $stmtRel = $conn->prepare($sqlRel);
    $stmtRel->bind_param("ii", $entrenador_id, $existingId);
    $stmtRel->execute();

    // Opcional: Si no tiene entrenador creador, se lo asignamos
    if (!$existingUser['entrenador_creador_id']) {
        $updSql = "UPDATE usuarios SET entrenador_creador_id = ? WHERE id = ?";
        $updStmt = $conn->prepare($updSql);
        $updStmt->bind_param("ii", $entrenador_id, $existingId);
        $updStmt->execute();
    }

    echo json_encode([
        "success" => true,
        "message" => "El usuario ya existe y ha sido asociado a tu lista.",
        "mail_sent" => false,
        "already_exists" => true,
        "user_id" => $existingId
    ]);
    exit;
}

// 2. Generar contraseña aleatoria (5 dígitos para mayor seguridad y formato esperado)
$randomPass = "Padel" . rand(10000, 99999);
$passwordHash = password_hash($randomPass, PASSWORD_DEFAULT);

// 3. Insertar nuevo usuario
$rol = 'alumno';
$sqlInsert = "INSERT INTO usuarios (usuario, password, rol, nombre, entrenador_creador_id) VALUES (?, ?, ?, ?, ?)";
$stmtInsert = $conn->prepare($sqlInsert);
$stmtInsert->bind_param("ssssi", $email, $passwordHash, $rol, $nombre, $entrenador_id);

if ($stmtInsert->execute()) {
    $newUserId = $conn->insert_id;
    
    // 4. Crear relación en tabla puente
    $sqlRel = "INSERT INTO entrenador_alumno (entrenador_id, alumno_id, creado_por_entrenador) VALUES (?, ?, 1)";
    $stmtRel = $conn->prepare($sqlRel);
    $stmtRel->bind_param("ii", $entrenador_id, $newUserId);
    $stmtRel->execute();

    // 5. Enviar correo de bienvenida
    $subject = "🎾 ¡Bienvenido a Padel Manager Academy!";
    $bodyHTML = "
    <html>
    <body style='margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif;'>
      <table width='100%' border='0' cellspacing='0' cellpadding='0' style='background-color: #f1f5f9; padding: 40px 20px;'>
        <tr>
          <td align='center'>
            <div style='max-width: 500px; width: 100%; background-color: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: 1px solid #e2e8f0;'>
              <div style='background-color: #ccff00; padding: 40px; text-align: center;'>
                <span style='font-size: 60px;'>🎾</span>
                <h1 style='margin: 20px 0 0; color: #000; font-size: 28px; font-weight: 900; text-transform: uppercase; letter-spacing: -1px;'>Padel Manager</h1>
              </div>
              <div style='padding: 40px 30px; text-align: left; color: #1e293b;'>
                <h2 style='margin: 0 0 20px; font-size: 22px; font-weight: 800; color: #000;'>¡Hola, " . htmlspecialchars($nombre) . "!</h2>
                <p style='margin: 0 0 20px; font-size: 16px; color: #475569; line-height: 1.6;'>
                  Tu entrenador te ha registrado en <strong>Padel Manager Academy</strong> para que puedas empezar a gestionar tus entrenamientos y ver tu evolución.
                </p>
                
                <div style='background-color: #f8fafc; border-left: 5px solid #ccff00; padding: 25px; margin-bottom: 30px; border-radius: 8px;'>
                  <p style='margin: 0 0 15px; font-size: 14px; color: #64748b; text-transform: uppercase; font-weight: 800; letter-spacing: 0.5px;'>Tus credenciales de acceso</p>
                  <p style='margin: 0 0 10px; font-size: 16px; font-weight: 700;'>Usuario: <span style='color: #2563eb;'>" . htmlspecialchars($email) . "</span></p>
                  <p style='margin: 0; font-size: 16px; font-weight: 700;'>Contraseña: <span style='font-family: monospace; background: #e2e8f0; padding: 4px 8px; border-radius: 4px; font-size: 18px;'>$randomPass</span></p>
                </div>

                <div style='text-align: center; margin-bottom: 30px;'>
                  <a href='https://www.padelmanager.cl' style='display: inline-block; background-color: #000; color: #ccff00; padding: 18px 40px; text-decoration: none; font-weight: 900; font-size: 16px; border-radius: 12px; box-shadow: 0 10px 20px rgba(0,0,0,0.1);'>DESCARGAR APP / LOGIN</a>
                </div>

                <p style='margin: 0; font-size: 14px; color: #94a3b8; text-align: center;'>
                  Te recomendamos cambiar esta contraseña al ingresar por primera vez.
                </p>
              </div>
              <div style='background-color: #f8fafc; padding: 25px; text-align: center; border-top: 1px solid #e2e8f0;'>
                <p style='margin: 0; font-size: 12px; color: #94a3b8;'>
                  &copy; " . date('Y') . " Padel Manager Academy. Todos los derechos reservados.
                </p>
              </div>
            </div>
          </td>
        </tr>
      </table>
    </body>
    </html>";

    $mailResult = enviarCorreoSMTP($email, $subject, $bodyHTML);
    
    echo json_encode([
        "success" => true,
        "message" => "Alumno creado con éxito",
        "mail_sent" => $mailResult['success'],
        "mail_error" => $mailResult['success'] ? null : ($mailResult['error'] ?? 'Error desconocido'),
        "user_id" => $newUserId
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        "success" => false, 
        "error" => "Error de base de datos",
        "details" => $stmtInsert->error
    ]);
}

$conn->close();
?>
