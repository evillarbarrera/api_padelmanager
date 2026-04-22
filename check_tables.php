<?php
require_once "db.php";
$res = $conn->query("SHOW TABLES");
$tables = [];
while($row = $res->fetch_array()) { $tables[] = $row[0]; }
echo json_encode($tables);
