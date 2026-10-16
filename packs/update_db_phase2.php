<?php
// Script Fase 2: Packs Multi-jugador
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once "../db.php";

echo "<h2>Actualizando Base de Datos para Fase 2 (Multi-jugador)...</h2>";

// 1. Agregar cantidad_personas a tabla packs
try {
    $sql1 = "ALTER TABLE packs ADD COLUMN cantidad_personas INT DEFAULT 1 AFTER categoria";
    if ($conn->query($sql1)) {
        echo "✅ Columna 'cantidad_personas' agregada a tabla 'packs'.<br>";
    } else {
        echo "⚠️ " . $conn->error . "<br>";
    }
} catch (Exception $e) {
    echo "⚠️ " . $e->getMessage() . "<br>";
}

// 2. Crear tabla para jugadores adicionales de un pack comprado
try {
    $sql2 = "CREATE TABLE IF NOT EXISTS pack_jugadores_adicionales (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pack_jugadores_id INT NOT NULL,
        jugador_id INT NOT NULL,
        fecha_asignacion DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (pack_jugadores_id) REFERENCES pack_jugadores(id) ON DELETE CASCADE,
        FOREIGN KEY (jugador_id) REFERENCES usuarios(id) ON DELETE CASCADE
    )";
    if ($conn->query($sql2)) {
        echo "✅ Tabla 'pack_jugadores_adicionales' creada.<br>";
    } else {
        echo "⚠️ " . $conn->error . "<br>";
    }
} catch (Exception $e) {
    echo "⚠️ " . $e->getMessage() . "<br>";
}

echo "<h3>Proceso finalizado.</h3>";
?>
