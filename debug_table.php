<?php
require_once "db.php";
$result = $conn->query("DESCRIBE torneo_partidos");
$columns = [];
while($row = $result->fetch_assoc()) {
    $columns[] = $row;
}
echo json_encode($columns);
?>
