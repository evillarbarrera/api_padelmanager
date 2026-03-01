<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

$admin_id = $_GET['admin_id'] ?? 0;

if (!$admin_id) {
    echo json_encode([]);
    exit;
}

// SILENT SCHEMA FIX - Ensure creator_id exists
$check = $conn->query("SHOW COLUMNS FROM torneos_v2 LIKE 'creator_id'");
if ($check && $check->num_rows == 0) {
    $conn->query("ALTER TABLE torneos_v2 ADD creator_id INT DEFAULT NULL AFTER club_id");
}

// Get tournaments for clubs managed by this admin
$sql = "SELECT t.*, c.nombre as club_nombre 
        FROM torneos_v2 t 
        JOIN clubes c ON t.club_id = c.id 
        WHERE c.admin_id = ?
        ORDER BY t.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();

$torneos = [];
while ($row = $result->fetch_assoc()) {
    $torneos[] = $row;
}

// Log simple para ver qué está pasando (puedes verlo en logs de PHP o ignorar)
// error_log("Torneos encontrados: " . count($torneos) . " de un total de $total");

echo json_encode($torneos);
exit;
?>
