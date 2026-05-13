<?php
require_once "../db.php";

$sql = "SELECT id, nombre, entrenador_id, tipo, COUNT(*) as qty 
        FROM packs 
        WHERE activo = 1 
        GROUP BY nombre, entrenador_id, tipo 
        HAVING qty > 1";

$result = $conn->query($sql);
$dupes = [];
while ($row = $result->fetch_assoc()) {
    $dupes[] = $row;
}

header("Content-Type: application/json");
echo json_encode($dupes, JSON_PRETTY_PRINT);
