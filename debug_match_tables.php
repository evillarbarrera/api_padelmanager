<?php
require_once "db.php";
$res1 = $conn->query("DESC torneo_partidos");
$res2 = $conn->query("DESC torneo_partidos_v2");
$cols1 = []; while($row = $res1->fetch_assoc()) $cols1[] = $row;
$cols2 = []; while($row = $res2->fetch_assoc()) $cols2[] = $row;
echo json_encode(["v1" => $cols1, "v2" => $cols2]);
?>
