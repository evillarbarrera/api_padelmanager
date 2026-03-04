<?php
require_once "db.php";
$res = $conn->query("DESCRIBE packs");
$cols = [];
while($row = $res->fetch_assoc()) {
    $cols[] = $row;
}
echo json_encode($cols);
