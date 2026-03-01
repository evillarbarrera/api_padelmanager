<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
require_once "../db.php";

function addColumnIfNotExists($conn, $table, $column, $definition) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($check->num_rows == 0) {
        $sql = "ALTER TABLE `$table` ADD `$column` $definition";
        if ($conn->query($sql)) {
            return "SUCCESS: Added $column to $table";
        } else {
            return "ERROR: Adding $column to $table: " . $conn->error;
        }
    }
    return "SKIP: $column already exists in $table";
}

$results = [];

// 1. Usuarios
$results[] = addColumnIfNotExists($conn, 'usuarios', 'puntos_ranking', "INT DEFAULT 0");

// 2. Torneos Americanos (Ranking columns)
$results[] = addColumnIfNotExists($conn, 'torneos_americanos', 'estado', "ENUM('Abierto', 'Cerrado') DEFAULT 'Abierto'");
$results[] = addColumnIfNotExists($conn, 'torneos_americanos', 'puntos_1er_lugar', "INT DEFAULT 100");
$results[] = addColumnIfNotExists($conn, 'torneos_americanos', 'puntos_2do_lugar', "INT DEFAULT 60");
$results[] = addColumnIfNotExists($conn, 'torneos_americanos', 'puntos_3er_lugar', "INT DEFAULT 40");
$results[] = addColumnIfNotExists($conn, 'torneos_americanos', 'puntos_4to_lugar', "INT DEFAULT 20");
$results[] = addColumnIfNotExists($conn, 'torneos_americanos', 'puntos_participacion', "INT DEFAULT 5");

// 3. Torneo Partidos (Finalization columns)
$results[] = addColumnIfNotExists($conn, 'torneo_partidos', 'finalizado', "TINYINT DEFAULT 0");
$results[] = addColumnIfNotExists($conn, 'torneo_partidos', 'puntos_t1', "INT DEFAULT 0");
$results[] = addColumnIfNotExists($conn, 'torneo_partidos', 'puntos_t2', "INT DEFAULT 0");

echo json_encode(["results" => $results]);
?>
