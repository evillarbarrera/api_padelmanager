<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
require_once "db.php";

// 1. Añadir columna categoria a entrenamiento_videos si no existe
$check = $conn->query("SHOW COLUMNS FROM entrenamiento_videos LIKE 'categoria'");
if ($check && $check->num_rows == 0) {
    $conn->query("ALTER TABLE entrenamiento_videos ADD `categoria` VARCHAR(50) DEFAULT 'Otros' AFTER `tipo` ");
}

echo json_encode(["success" => true, "message" => "Base de datos actualizada para categorías de video"]);
?>
