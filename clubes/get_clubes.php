<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
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

$admin_id = $_GET['admin_id'] ?? 0;

if ($admin_id) {
    $sql = "SELECT c.*, d.region, d.comuna 
            FROM clubes c 
            LEFT JOIN direcciones d ON d.club_id = c.id 
            WHERE c.admin_id = ? 
            ORDER BY c.nombre ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $admin_id);
} else {
    $sql = "SELECT c.*, d.region, d.comuna 
            FROM clubes c 
            LEFT JOIN direcciones d ON d.club_id = c.id 
            ORDER BY c.nombre ASC";
    $stmt = $conn->prepare($sql);
}

$stmt->execute();
$result = $stmt->get_result();

$clubes = [];
while ($row = $result->fetch_assoc()) {
    $clubes[] = $row;
}

echo json_encode($clubes);
?>
