<?php
require_once "db.php";
$res = $conn->query("DESC usuarios");
$cols = [];
while($row = $res->fetch_assoc()) $cols[] = $row;
echo json_encode($cols);
?>
