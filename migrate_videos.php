<?php
require_once "db.php";

$statements = [
    "ALTER TABLE entrenamiento_videos ADD COLUMN categoria varchar(100) DEFAULT 'General'",
    "ALTER TABLE entrenamiento_videos ADD COLUMN tipo varchar(50) DEFAULT 'clase'",
    "ALTER TABLE entrenamiento_videos ADD COLUMN comentario text",
    "ALTER TABLE entrenamiento_videos ADD COLUMN titulo varchar(150)"
];

foreach ($statements as $sql) {
    if ($conn->query($sql)) {
        echo "Exito: " . $sql . "<br>";
    } else {
        echo "Error: " . $conn->error . "<br>";
    }
}
echo "Migracion completada";
?>
