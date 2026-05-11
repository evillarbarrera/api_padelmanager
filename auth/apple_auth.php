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

// Ensure apple_id column exists
$checkColumn = $conn->query("SHOW COLUMNS FROM usuarios LIKE 'apple_id'");
if ($checkColumn->num_rows == 0) {
    $conn->query("ALTER TABLE usuarios ADD COLUMN apple_id VARCHAR(255) NULL UNIQUE");
}

$data = json_decode(file_get_contents("php://input"), true);
$email = $data['email'] ?? '';
$appleUser = $data['user'] ?? '';
$nombre = $data['nombre'] ?? 'Usuario Apple';

if (empty($appleUser)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Identificador de Apple requerido"]);
    exit;
}

// 1. Buscar por apple_id primero (Inicios de sesión subsecuentes o registro previo)
$sql = "SELECT id, usuario, rol, nombre FROM usuarios WHERE apple_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $appleUser);
$stmt->execute();
$result = $stmt->get_result();

if ($user = $result->fetch_assoc()) {
    $token = base64_encode($user['id'] . "|padel_academy");
    echo json_encode([
        "success" => true, "exists" => true, "token" => $token, 
        "rol" => $user['rol'], "id" => $user['id'], "nombre" => $user['nombre']
    ]);
    exit;
}

// 2. Si no existe por apple_id, buscar por email (si se proporcionó)
if (!empty($email)) {
    $sqlEmail = "SELECT id, usuario, rol, nombre FROM usuarios WHERE usuario = ?";
    $stmtEmail = $conn->prepare($sqlEmail);
    $stmtEmail->bind_param("s", $email);
    $stmtEmail->execute();
    $resultEmail = $stmtEmail->get_result();

    if ($user = $resultEmail->fetch_assoc()) {
        // Encontramos la cuenta por email. Guardamos su apple_id.
        $update = "UPDATE usuarios SET apple_id = ? WHERE id = ?";
        $stmtUp = $conn->prepare($update);
        $stmtUp->bind_param("si", $appleUser, $user['id']);
        $stmtUp->execute();

        $token = base64_encode($user['id'] . "|padel_academy");
        echo json_encode([
            "success" => true, "exists" => true, "token" => $token, 
            "rol" => $user['rol'], "id" => $user['id'], "nombre" => $user['nombre']
        ]);
        exit;
    }
}

// 3. Usuario NO EXISTE -> REGISTRO AUTOMÁTICO (CRÍTICO PARA APPLE REVIEW)
// Si no hay email, generamos uno basado en el appleUser para que no falle el registro
if (empty($email)) {
    $email = "apple_" . substr($appleUser, 0, 12) . "@privaterelay.appleid.com";
}

$rol = 'jugador'; // Rol por defecto
$pass = bin2hex(random_bytes(8)); // Password aleatorio interno

$insert = "INSERT INTO usuarios (nombre, usuario, password, rol, apple_id) VALUES (?, ?, ?, ?, ?)";
$stmtIns = $conn->prepare($insert);
$stmtIns->bind_param("sssss", $nombre, $email, $pass, $rol, $appleUser);

if ($stmtIns->execute()) {
    $newId = $stmtIns->insert_id;
    $token = base64_encode($newId . "|padel_academy");
    echo json_encode([
        "success" => true, "exists" => true, "token" => $token, 
        "rol" => $rol, "id" => $newId, "nombre" => $nombre
    ]);
} else {
    // Si falla el insert por duplicado de email (raro si es apple_<id>), intentamos buscarlo una vez más
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Error al crear cuenta automática"]);
}

