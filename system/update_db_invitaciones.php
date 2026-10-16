<?php
// Script para actualizar la tabla pack_jugadores_adicionales
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once "../db.php";

echo "<h2>Actualizando tabla pack_jugadores_adicionales...</h2>";

try {
    // Agregar columna estado
    $sql1 = "ALTER TABLE pack_jugadores_adicionales ADD COLUMN IF NOT EXISTS estado ENUM('pendiente', 'aceptado') DEFAULT 'pendiente'";
    if ($conn->query($sql1)) {
        echo "✅ Columna 'estado' procesada.<br>";
    }

    // Agregar columna token
    $sql2 = "ALTER TABLE pack_jugadores_adicionales ADD COLUMN IF NOT EXISTS token VARCHAR(100) DEFAULT NULL";
    if ($conn->query($sql2)) {
        echo "✅ Columna 'token' procesada.<br>";
    }

    // Marcar los existentes como aceptados
    $sql3 = "UPDATE pack_jugadores_adicionales SET estado = 'aceptado' WHERE estado IS NULL OR estado = ''";
    $conn->query($sql3);

    echo "<h3>Proceso finalizado.</h3>";
} catch (Exception $e) {
    echo "⚠️ Error: " . $e->getMessage() . "<br>";
}
?>
