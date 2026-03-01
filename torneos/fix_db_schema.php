<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
require_once "../db.php";

$statements = [
    "ALTER TABLE torneo_partidos ADD COLUMN IF NOT EXISTS puntos_t1 INT DEFAULT 0",
    "ALTER TABLE torneo_partidos ADD COLUMN IF NOT EXISTS puntos_t2 INT DEFAULT 0",
    "ALTER TABLE torneo_partidos ADD COLUMN IF NOT EXISTS finalizado TINYINT DEFAULT 0"
];

$results = [];

foreach ($statements as $sql) {
    if ($conn->query($sql) === TRUE) {
        $results[] = ["status" => "success", "sql" => $sql];
    } else {
        $results[] = ["status" => "error", "sql" => $sql, "error" => $conn->error];
    }
}

echo json_encode($results);
?>
