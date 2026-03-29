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

$nombre = $data['nombre'] ?? '';
$email = $data['email'] ?? ''; 
$rol = $data['rol'] ?? '';
// google_id o avatar si se decidiera guardar

if (empty($nombre) || empty($email) || empty($rol)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Faltan datos"]);
    exit;
}

// Verificar existencia
$sqlCheck = "SELECT id FROM usuarios WHERE usuario = ?";
$stmtCheck = $conn->prepare($sqlCheck);
$stmtCheck->bind_param("s", $email);
$stmtCheck->execute();
if ($stmtCheck->get_result()->num_rows > 0) {
    http_response_code(409); 
    echo json_encode(["success" => false, "error" => "El usuario ya existe"]);
    exit;
}

// Generar password aleatorio (ya que entra por Google)
$randomPass = bin2hex(random_bytes(10));
$passwordHash = password_hash($randomPass, PASSWORD_DEFAULT);

$sqlInsert = "INSERT INTO usuarios (usuario, password, rol, nombre) VALUES (?, ?, ?, ?)";
$stmtInsert = $conn->prepare($sqlInsert);
$stmtInsert->bind_param("ssss", $email, $passwordHash, $rol, $nombre);

if ($stmtInsert->execute()) {
    $token = base64_encode("1|padel_academy");

    echo json_encode([
        "success" => true,
        "token" => $token,
        "usuario" => ["id" => $newId, "nombre" => $nombre, "rol" => $rol]
    ]);
} else {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Error al registrar: " . $stmtInsert->error]);
}
