<?php
require_once "db.php";

$sql = "ALTER TABLE clubes 
        ADD COLUMN IF NOT EXISTS reservas_activas TINYINT(1) DEFAULT 0,
        ADD COLUMN IF NOT EXISTS academia_activa TINYINT(1) DEFAULT 1";

if ($conn->query($sql) === TRUE) {
    echo "Base de datos actualizada con éxito: Columnas reservas_activas y academia_activa añadidas.\n";
} else {
    echo "Error al actualizar la base de datos: " . $conn->error . "\n";
}

$conn->close();
?>
