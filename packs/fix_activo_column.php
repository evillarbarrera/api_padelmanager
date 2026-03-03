<?php
// Script para asegurar que la columna 'activo' existe e inicializarla
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once "../db.php";

echo "<h2>Asegurando columna 'activo' en tabla packs...</h2>";

try {
    // 1. Intentar agregar la columna activo (por si no existe)
    $sql = "ALTER TABLE packs ADD COLUMN activo TINYINT(1) DEFAULT 1";
    if ($conn->query($sql)) {
        echo "✅ Columna 'activo' agregada correctamente.<br>";
    } else {
        // Si ya existe, nos aseguramos de que todos los registros tengan 1 por defecto si son nuevos o nulos
        echo "ℹ️ La columna 'activo' ya existe o hubo un error: " . $conn->error . "<br>";
        
        $sql2 = "UPDATE packs SET activo = 1 WHERE activo IS NULL";
        if ($conn->query($sql2)) {
            echo "✅ Registros existentes actualizados a activo = 1.<br>";
        }
    }
} catch (Exception $e) {
    echo "⚠️ " . $e->getMessage() . "<br>";
}

echo "<h3>Proceso finalizado.</h3>";
?>
