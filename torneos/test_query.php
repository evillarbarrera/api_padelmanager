<?php
header("Content-Type: application/json");
require_once "../db.php";

$admin_id = $_GET['admin_id'] ?? 1; // Default to 1 for testing

$sql = "SELECT t.*, c.nombre as club_nombre 
        FROM torneos_v2 t 
        LEFT JOIN clubes c ON t.club_id = c.id 
        WHERE c.admin_id = $admin_id OR t.creator_id = $admin_id
        ORDER BY t.fecha_inicio DESC";

$res = $conn->query($sql);
$data = [];
if ($res) {
    while($row = $res->fetch_assoc()) $data[] = $row;
} else {
    $data = ["error" => $conn->error];
}

echo json_encode(["test_id" => $admin_id, "results" => $data, "query" => $sql]);
?>
