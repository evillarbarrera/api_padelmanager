<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../auth/auth_helper.php";
$tokenUserId = validateToken();
if (!$tokenUserId) {
    sendUnauthorized();
}

header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
    http_response_code(200);
    exit;
}



require_once "../db.php";
require_once "../system/mail_service.php";

// SILENT SCHEMA FIX
function ensureColumnUsers($conn, $column, $definition) {
    $check = $conn->query("SHOW COLUMNS FROM usuarios LIKE '$column'");
    if ($check && $check->num_rows == 0) {
        $conn->query("ALTER TABLE usuarios ADD `$column` $definition");
    }
}
ensureColumnUsers($conn, 'entrenador_creador_id', "INT NULL DEFAULT NULL AFTER `rol` ");

// Ensure table exists
$conn->query("CREATE TABLE IF NOT EXISTS entrenador_alumno (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entrenador_id INT NOT NULL,
    alumno_id INT NOT NULL,
    fecha_asociacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    creado_por_entrenador TINYINT(1) DEFAULT 0,
    UNIQUE KEY unique_rel (entrenador_id, alumno_id)
)");

$data = json_decode(file_get_contents("php://input"), true);

$nombre = $data['nombre'] ?? '';
$email = $data['email'] ?? '';

// 🧠 AUTOCORRECCIÓN: Si el nombre contiene una @ y el email no tiene @ (o está vacío), los intercambiamos.
if (strpos($nombre, '@') !== false && strpos($email, '@') === false) {
    $temp = $nombre;
    $nombre = $email;
    $email = $temp;
}
$entrenador_id = $data['entrenador_id'] ?? null;

if (empty($nombre) || empty($email) || !$entrenador_id) {
    http_response_code(400);
    echo json_encode(["error" => "Nombre, email y entrenador_id son obligatorios"]);
    exit;
}

// 1. Verificar si el usuario ya existe
$checkSql = "SELECT id, nombre, entrenador_creador_id FROM usuarios WHERE usuario = ?";
$checkStmt = $conn->prepare($checkSql);
$checkStmt->bind_param("s", $email);
$checkStmt->execute();
$resCheck = $checkStmt->get_result();

if ($resCheck->num_rows > 0) {
    $existingUser = $resCheck->fetch_assoc();
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
        "message" => "El alumno ya existía y ha sido asociado a tu lista correctamente.",
        "user_id" => $existingId,
        "mail_sent" => false // No mandamos clave si ya existía
    ]);
    exit;
}

// 2. Generar contraseña genérica
$genericPass = "Padel" . rand(1000, 9999);
$hashedPass = password_hash($genericPass, PASSWORD_DEFAULT);

// 3. Insertar usuario
$sql = "INSERT INTO usuarios (nombre, usuario, password, rol, entrenador_creador_id) VALUES (?, ?, ?, 'jugador', ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sssi", $nombre, $email, $hashedPass, $entrenador_id);

if ($stmt->execute()) {
    $nuevo_id = $stmt->insert_id;

    // 4. Crear relación en tabla puente (opcional pero consistente con el setup anterior)
    $sqlRel = "INSERT INTO entrenador_alumno (entrenador_id, alumno_id, creado_por_entrenador) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE creado_por_entrenador = 1";
    $stmtRel = $conn->prepare($sqlRel);
    $stmtRel->bind_param("ii", $entrenador_id, $nuevo_id);
    $stmtRel->execute();

    // 5. Enviar correo de bienvenida
    $subject = "Bienvenido a Training Padel Academy";
    $body = "
        <div style='font-family: Arial, sans-serif; padding: 20px; color: #333;'>
            <h2 style='color: #ccff00;'>¡Hola $nombre!</h2>
            <p>Tu entrenador te ha registrado en <b>Training Padel Academy</b>.</p>
            <p>A partir de ahora podrás realizar el seguimiento de tus clases, ver tus evaluaciones y videos de entrenamiento.</p>
            <div style='background: #f4f4f4; padding: 15px; border-radius: 8px; margin-top: 20px;'>
                <p><b>Tus credenciales de acceso:</b></p>
                <p><b>Usuario:</b> $email</p>
                <p><b>Contraseña temporal:</b> $genericPass</p>
            </div>
            <p style='margin-top: 20px;'>Puedes acceder desde nuestra web: <a href='https://padelmanager.cl' style='color: #ccff00;'>padelmanager.cl</a></p>
            <p><i>Te recomendamos cambiar tu contraseña una vez ingreses a tu perfil.</i></p>
        </div>
    ";

    $mailResult = enviarCorreoSMTP($email, $subject, $body);

    echo json_encode([
        "success" => true, 
        "message" => "Alumno creado con éxito",
        "user_id" => $nuevo_id,
        "mail_sent" => $mailResult['success']
    ]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al crear el usuario: " . $conn->error]);
}

$conn->close();
?>
