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
require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
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
$search = $_GET['search'] ?? null;
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;

$sql = "SELECT id, nombre, usuario, foto, foto_perfil, rol, categoria, descripcion FROM usuarios WHERE 1=1";

if ($rol && $rol !== 'all' && $rol !== 'any') {
    $sql .= " AND rol = '" . $conn->real_escape_string($rol) . "'";
}

if ($search) {
    $s = $conn->real_escape_string($search);
    // Case insensitive approach
    $sql .= " AND (LOWER(nombre) LIKE LOWER('%$s%') OR LOWER(usuario) LIKE LOWER('%$s%'))";
}

$sql .= " ORDER BY nombre ASC LIMIT $limit";

$result = $conn->query($sql);

$data = [];
while ($row = $result->fetch_assoc()) {
  $data[] = $row;
}

echo json_encode($data);
