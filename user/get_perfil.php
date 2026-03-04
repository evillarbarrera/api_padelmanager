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

// Diagnostic Log
$headers = getallheaders();
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

// Intentar capturar de PHP_AUTH_USER si es Basic (a veces apache lo mapea así)
if (empty($auth) && isset($_SERVER['PHP_AUTH_USER'])) {
    $auth = 'Bearer ' . $_SERVER['PHP_AUTH_USER'];
}

$expectedToken = 'Bearer ' . base64_encode("1|padel_academy");

// Debug log to a file on the server (if permissions allow)
// file_put_contents('auth_debug.log', date('Y-m-d H:i:s') . " - Received: " . $auth . "\n", FILE_APPEND);

if (trim($auth) !== trim($expectedToken)) {
    http_response_code(401);
    
    $received_info = [
        "auth_present" => !empty($auth),
        "received" => (string)substr($auth, 0, 15) . "...",
        "expected" => (string)substr($expectedToken, 0, 15) . "...",
        "method" => $_SERVER['REQUEST_METHOD'],
        "all_headers_keys" => array_keys($headers)
    ];

    echo json_encode([
        "error" => "Unauthorized",
        "details" => "Token mismatch or missing",
        "debug" => $received_info
    ]);
    exit;
}

require_once "../db.php";

// SILENT SCHEMA FIX - Ensure columns exist
function ensureColumnUsers($conn, $column, $definition) {
    $check = $conn->query("SHOW COLUMNS FROM usuarios LIKE '$column'");
    if ($check && $check->num_rows == 0) {
        $conn->query("ALTER TABLE usuarios ADD `$column` $definition");
    }
}
ensureColumnUsers($conn, 'instagram', "VARCHAR(255) NULL");
ensureColumnUsers($conn, 'facebook', "VARCHAR(255) NULL");
ensureColumnUsers($conn, 'telefono', "VARCHAR(50) NULL");
ensureColumnUsers($conn, 'categoria', "VARCHAR(50) DEFAULT 'Cuarta'");
ensureColumnUsers($conn, 'google_id', "VARCHAR(255) NULL");
ensureColumnUsers($conn, 'proveedor', "VARCHAR(50) DEFAULT 'App'");
ensureColumnUsers($conn, 'foto', "VARCHAR(255) NULL");
ensureColumnUsers($conn, 'foto_perfil', "VARCHAR(255) NULL");
ensureColumnUsers($conn, 'mp_collector_id', "VARCHAR(100) NULL");


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
