<?php
header("Content-Type: application/json");
require_once "../db.php";

$res = $conn->query("DESCRIBE clubes");
$cols = [];
while($row = $res->fetch_assoc()) $cols[] = $row['Field'];

$res2 = $conn->query("SELECT * FROM clubes LIMIT 5");
$data = [];
while($row = $res2->fetch_assoc()) $data[] = $row;

echo json_encode(["columns" => $cols, "data" => $data]);
?>
