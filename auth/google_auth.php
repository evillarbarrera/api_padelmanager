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

// Validar token de Google aquí si se desea mayor seguridad (recomendado)
// Por simplicidad y rapidez inicial, confiaremos en que el cliente envía el email autenticado por SDK
// PROD: Usar Google Client Library para verificar id_token

if (empty($email)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Email requerido"]);
    exit;
}

$sql = "SELECT id, usuario, rol, nombre FROM usuarios WHERE usuario = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($user = $result->fetch_assoc()) {
    // Usuario EXISTE -> Login exitoso
    $token = base64_encode($user['id'] . "|padel_academy");
    
    echo json_encode([
        "success" => true,
        "exists" => true,
        "token" => $token,
        "rol" => $user['rol'],
        "id" => $user['id'],
        "nombre" => $user['nombre']
    ]);
} else {
    // Usuario NO EXISTE -> Requiere registro (selección de rol)
    // Retornamos 200 pero con success false o flag 'exists': false
    echo json_encode([
        "success" => true,
        "exists" => false, 
        "error" => "Usuario nuevo, debe registrarse"
    ]);
}
