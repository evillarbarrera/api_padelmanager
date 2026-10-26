<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

// Manejo de peticiones preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$headers = getallheaders();
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';

if (empty($auth)) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$admin_id = $_GET['admin_id'] ?? 0;
$club_id = $_GET['club_id'] ?? null;

if (!$admin_id) {
    echo json_encode([]);
    exit;
}

// SILENT SCHEMA FIX - Ensure creator_id and estado exist
$check = $conn->query("SHOW COLUMNS FROM torneos_americanos LIKE 'creator_id'");
if ($check && $check->num_rows == 0) {
    @$conn->query("ALTER TABLE torneos_americanos ADD creator_id INT DEFAULT NULL AFTER club_id");
}

$checkEstado = $conn->query("SHOW COLUMNS FROM torneos_americanos LIKE 'estado'");
if ($checkEstado && $checkEstado->num_rows == 0) {
    @$conn->query("ALTER TABLE torneos_americanos ADD estado VARCHAR(50) DEFAULT 'Abierto'");
} else {
    @$conn->query("ALTER TABLE torneos_americanos MODIFY COLUMN estado VARCHAR(50) DEFAULT 'Abierto'");
}

// Auto-cerrar torneos en base de datos cuya fecha ya pasó
$today = date('Y-m-d');
@$conn->query("UPDATE torneos_americanos SET estado = 'Cerrado' WHERE (estado = 'Abierto' OR estado IS NULL OR estado = '') AND fecha < '$today'");

// Get tournaments for clubs managed by this admin or where they are staff
if ($club_id) {
    $sql = "SELECT t.*, 
                   CASE 
                       WHEN t.fecha < CURDATE() THEN 'Cerrado'
                       ELSE COALESCE(NULLIF(TRIM(t.estado), ''), 'Abierto')
                   END AS estado,
                   c.nombre as club_nombre 
            FROM torneos_americanos t 
            LEFT JOIN clubes c ON t.club_id = c.id 
            WHERE t.club_id = ?
            ORDER BY t.fecha DESC, t.hora_inicio DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $club_id);
} else {
    $sql = "SELECT t.*, 
                   CASE 
                       WHEN t.fecha < CURDATE() THEN 'Cerrado'
                       ELSE COALESCE(NULLIF(TRIM(t.estado), ''), 'Abierto')
                   END AS estado,
                   c.nombre as club_nombre 
            FROM torneos_americanos t 
            LEFT JOIN clubes c ON t.club_id = c.id 
            WHERE (c.admin_id = ? OR c.id IN (SELECT club_id FROM usuarios_clubes WHERE usuario_id = ? AND activo = 1))
            ORDER BY t.fecha DESC, t.hora_inicio DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $admin_id, $admin_id);
}

$stmt->execute();
$result = $stmt->get_result();

$torneos = [];
while ($row = $result->fetch_assoc()) {
    if (!empty($row['fecha']) && $row['fecha'] < $today) {
        $row['estado'] = 'Cerrado';
    } elseif (empty($row['estado'])) {
        $row['estado'] = 'Abierto';
    }
    $torneos[] = $row;
}

echo json_encode($torneos);
