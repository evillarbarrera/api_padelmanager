<?php
header("Content-Type: application/json");
require_once "../db.php";

$tables = ["torneos_v2", "torneo_categorias", "torneo_parejas", "torneo_inscripciones", "torneo_grupos", "torneo_grupo_parejas", "torneo_partidos_v2"];
$status = [];

foreach ($tables as $table) {
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    $status[$table] = ($res->num_rows > 0) ? "EXISTS" : "MISSING";
    
    if ($status[$table] === "EXISTS") {
        $cols_res = $conn->query("DESCRIBE $table");
        $cols = [];
        while($row = $cols_res->fetch_assoc()) {
            $cols[] = $row['Field'];
        }
        $status[$table . "_cols"] = $cols;
    }
}

echo json_encode($status);
?>
