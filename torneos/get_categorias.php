<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

$torneo_id = $_GET['torneo_id'] ?? 0;

if (!$torneo_id) {
    echo json_encode([]);
    exit;
}

$sql = "SELECT * FROM torneo_categorias WHERE torneo_id = ? ORDER BY nombre ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $torneo_id);
$stmt->execute();
$result = $stmt->get_result();

$categorias = [];
while ($row = $result->fetch_assoc()) {
    $categorias[] = $row;
}

echo json_encode($categorias);
?>
