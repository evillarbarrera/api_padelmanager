<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

$res = $conn->query("SELECT id, nombre, puntos_ranking FROM usuarios WHERE puntos_ranking > 0");
$data = [];
while($row = $res->fetch_assoc()) $data[] = $row;

echo json_encode([
    "time" => date("Y-m-d H:i:s"),
    "database" => $dbname,
    "has_points" => $data
]);
?>
