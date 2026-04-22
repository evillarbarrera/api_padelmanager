<?php
require_once "db.php";

echo "Iniciando migración...<br>";

// 1. Add 'tipo' column if it doesn't exist
$sql = "ALTER TABLE entrenamiento_videos ADD COLUMN tipo ENUM('clase', 'personal') DEFAULT 'clase' AFTER id";
if ($conn->query($sql)) {
    echo "Columna 'tipo' añadida correctamente.<br>";
} else {
    echo "Nota: Columna 'tipo' ya existía o error: " . $conn->error . "<br>";
}

// 2. Make 'entrenador_id' nullable for personal videos
$sql = "ALTER TABLE entrenamiento_videos MODIFY COLUMN entrenador_id INT(11) NULL";
if ($conn->query($sql)) {
    echo "Columna 'entrenador_id' ahora permite valores nulos (nullable).<br>";
} else {
    echo "Error al modificar 'entrenador_id': " . $conn->error . "<br>";
}

echo "Migración terminada.";
?>
