<?php
require_once "db.php";
$result = $conn->query("SHOW TABLES LIKE '%reserva%'");
$tables = [];
while($row = $result->fetch_array()) { $tables[] = $row[0]; }
echo json_encode($tables);
?>
