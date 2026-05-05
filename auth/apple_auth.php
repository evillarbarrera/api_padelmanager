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

// Auto-fix: Asegurar que existe la columna apple_id para almacenar el user ID de Apple
$conn->query("ALTER TABLE usuarios ADD COLUMN apple_id VARCHAR(255) NULL UNIQUE");

$data = json_decode(file_get_contents("php://input"), true);
$email = $data['email'] ?? '';
$appleUser = $data['user'] ?? '';

if (empty($appleUser)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Identificador de Apple requerido"]);
    exit;
}

// 1. Buscar por apple_id primero (Inicios de sesión subsecuentes donde Apple oculta el email)
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

// 2. Si no existe por apple_id, buscar por email (Primera vez que inician sesión, Apple sí envía el email)
if (!empty($email)) {
    $sqlEmail = "SELECT id, usuario, rol, nombre FROM usuarios WHERE usuario = ?";
    $stmtEmail = $conn->prepare($sqlEmail);
    $stmtEmail->bind_param("s", $email);
    $stmtEmail->execute();
    $resultEmail = $stmtEmail->get_result();

    if ($user = $resultEmail->fetch_assoc()) {
        // Encontramos la cuenta por email. Guardamos su apple_id para siempre.
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

// 3. Usuario NO EXISTE -> Requiere registro
echo json_encode([
    "success" => true,
    "exists" => false, 
    "error" => "Usuario nuevo, debe registrarse"
]);
