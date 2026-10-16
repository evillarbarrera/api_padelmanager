<?php
require_once "db.php";
$tables = ['packs', 'pack_jugadores', 'inscripciones_grupales', 'pack_jugadores_adicionales'];
$schema = [];
foreach ($tables as $t) {
    $res = $conn->query("DESCRIBE $t");
    if ($res) {
        while($row = $res->fetch_assoc()) $schema[$t][] = $row;
    } else {
        $schema[$t] = "Error: " . $conn->error;
    }
}
echo json_encode($schema, JSON_PRETTY_PRINT);
?>
