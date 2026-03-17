<?php
require_once "../db.php";

// 1. Drop existing unique index if it exists
$sqlDrop = "ALTER TABLE tips_diarios_ia DROP INDEX unique_fecha";
if ($conn->query($sqlDrop)) {
    echo "Index unique_fecha eliminado correctamente.\n";
} else {
    echo "Index not found or error: " . $conn->error . "\n";
}

// 2. Add 'posicion' or just allow multiple
// It's better to add 'posicion' to keep it organized (1 or 2)
$sqlAddPos = "ALTER TABLE tips_diarios_ia ADD COLUMN IF NOT EXISTS posicion TINYINT DEFAULT 1";
if ($conn->query($sqlAddPos)) {
    echo "Columna 'posicion' añadida correctamente.\n";
} else {
    echo "Error adding posicion: " . $conn->error . "\n";
}

// 3. Add new UNIQUE key for (fecha, posicion)
$sqlNewUnique = "ALTER TABLE tips_diarios_ia ADD UNIQUE KEY unique_fecha_pos (fecha, posicion)";
if ($conn->query($sqlNewUnique)) {
    echo "Nuevo index unique_fecha_pos creado correctamente.\n";
} else {
    echo "Error adding new index or it already exists: " . $conn->error . "\n";
}
?>
