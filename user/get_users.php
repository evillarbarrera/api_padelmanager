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

$headers = getallheaders();
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

$expectedToken = 'Bearer ' . base64_encode("1|padel_academy");

if (empty($auth) || $auth !== $expectedToken) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized", "details" => "Token mismatch or missing"]);
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
ensureColumnUsers($conn, 'descripcion', "TEXT NULL");
ensureColumnUsers($conn, 'categoria', "VARCHAR(50) DEFAULT 'Cuarta'");
ensureColumnUsers($conn, 'foto', "VARCHAR(255) NULL");
ensureColumnUsers($conn, 'foto_perfil', "VARCHAR(255) NULL");

$rol = $_GET['rol'] ?? null;
$sql = "SELECT id, nombre, foto, foto_perfil, rol, categoria, descripcion FROM usuarios";
if ($rol) {
    $sql .= " WHERE rol = '" . $conn->real_escape_string($rol) . "'";
}

$result = $conn->query($sql);

$data = [];
while ($row = $result->fetch_assoc()) {
  $data[] = $row;
}

echo json_encode($data);
