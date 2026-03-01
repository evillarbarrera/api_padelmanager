<?php
require_once "db.php";

$sql = "ALTER TABLE entrenamiento_videos ADD COLUMN ai_report TEXT AFTER comentario";
if ($conn->query($sql)) {
    echo "Columna ai_report añadida correctamente.";
} else {
    echo "Error o la columna ya existe: " . $conn->error;
}
?>
