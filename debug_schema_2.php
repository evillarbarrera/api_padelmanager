<?php
require_once "db.php";
$tables = ['torneos_americanos', 'canchas', 'clubes'];
$schema = [];
foreach($tables as $t) {
    $res = $conn->query("DESCRIBE $t");
    while($row = $res->fetch_assoc()) { $schema[$t][] = $row; }
}
echo json_encode($schema);
