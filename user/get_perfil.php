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

require_once "../auth/auth_helper.php";

$userId = validateToken();
if (!$userId) {
    sendUnauthorized();
}

require_once "../db.php";

$user_id = $_GET['user_id'] ?? 0;

if (!$user_id) {
    http_response_code(400);
    echo json_encode(["error" => "user_id is required"]);
    exit;
}

// 1. Fetch user data
$sqlUser = "SELECT id, nombre, usuario, rol, foto, foto_perfil, instagram, facebook, telefono, categoria, descripcion, created_at, google_id, proveedor, banco_titular, banco_rut, banco_nombre, banco_tipo_cuenta, banco_numero_cuenta, mp_collector_id FROM usuarios WHERE id = ?";

$stmtUser = $conn->prepare($sqlUser);
$stmtUser->bind_param("i", $user_id);
$stmtUser->execute();
$resUser = $stmtUser->get_result();
$userData = $resUser->fetch_assoc();

if (!$userData) {
    http_response_code(404);
    echo json_encode(["error" => "User not found"]);
    exit;
}

// 2. Fetch address data
$sqlAddr = "SELECT region, comuna, calle, numero_casa, referencia FROM direcciones WHERE usuario_id = ?";
$stmtAddr = $conn->prepare($sqlAddr);
$stmtAddr->bind_param("i", $user_id);
$stmtAddr->execute();
$resAddr = $stmtAddr->get_result();
$addrData = $resAddr->fetch_assoc();

echo json_encode([
    "success" => true,
    "user" => $userData,
    "direccion" => $addrData ?? null
]);
