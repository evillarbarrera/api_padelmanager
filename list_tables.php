<?php
require_once "db.php";
$res = $conn->query("SHOW TABLES");
$tables = [];
while($row = $res->fetch_row()) {
    $tables[] = $row[0];
}
header('Content-Type: application/json');
echo json_encode($tables);
