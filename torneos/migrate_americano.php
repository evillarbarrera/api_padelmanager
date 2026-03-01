<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
require_once "../db.php";

function addColumn($conn, $table, $column, $definition) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($check && $check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD `$column` $definition");
        return true;
    }
    return false;
}

$results = [];

// torneos_americanos
$results['torneos_americanos'][] = addColumn($conn, 'torneos_americanos', 'tipo_torneo', "ENUM('estandar', 'grupos') DEFAULT 'estandar'");
$results['torneos_americanos'][] = addColumn($conn, 'torneos_americanos', 'modalidad', "ENUM('unicategoria', 'suma', 'mixto') DEFAULT 'unicategoria'");
$results['torneos_americanos'][] = addColumn($conn, 'torneos_americanos', 'valor_suma', "INT NULL");
$results['torneos_americanos'][] = addColumn($conn, 'torneos_americanos', 'genero', "VARCHAR(20) NULL");

// torneo_partidos
$results['torneo_partidos'][] = addColumn($conn, 'torneo_partidos', 'grupo_id', "VARCHAR(10) NULL");
$results['torneo_partidos'][] = addColumn($conn, 'torneo_partidos', 'fase', "VARCHAR(50) DEFAULT 'Grupos'");

// torneo_participantes
$results['torneo_participantes'][] = addColumn($conn, 'torneo_participantes', 'grupo_id', "VARCHAR(10) NULL");

echo json_encode(["success" => true, "migration_results" => $results]);
?>
