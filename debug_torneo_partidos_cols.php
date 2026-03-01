<?php
require_once "db.php";
$res = $conn->query("DESC torneo_partidos");
$cols = []; while($row = $res->fetch_assoc()) $cols[] = $row;
echo json_encode($cols);
?>
