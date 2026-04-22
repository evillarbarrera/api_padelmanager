<?php
require_once "db.php";
$res = $conn->query("SELECT * FROM packs");
$data = [];
while($row = $res->fetch_assoc()) $data[] = $row;
echo json_encode($data, JSON_PRETTY_PRINT);
?>
