<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

$headers = getallheaders();
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';

if (empty($auth)) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$admin_id = $_GET['admin_id'] ?? 0;

if (!$admin_id) {
    echo json_encode([]);
    exit;
}

// SILENT SCHEMA FIX - Ensure creator_id exists
$check = $conn->query("SHOW COLUMNS FROM torneos_americanos LIKE 'creator_id'");
if ($check && $check->num_rows == 0) {
    $conn->query("ALTER TABLE torneos_americanos ADD creator_id INT DEFAULT NULL AFTER club_id");
}

// Get tournaments for clubs managed by this admin
$sql = "SELECT t.*, c.nombre as club_nombre 
        FROM torneos_americanos t 
        JOIN clubes c ON t.club_id = c.id 
        WHERE c.admin_id = ?
        ORDER BY t.fecha DESC, t.hora_inicio DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();

$torneos = [];
while ($row = $result->fetch_assoc()) {
    $torneos[] = $row;
}

echo json_encode($torneos);
