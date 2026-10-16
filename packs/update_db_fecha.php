<?php
require_once "../db.php";

echo "<h2>Actualizando Base de Datos para Entrenamientos Grupales por Fecha...</h2>";

// 1. Agregar columna 'fecha' a tabla packs
try {
    $sql1 = "ALTER TABLE packs ADD COLUMN fecha DATE NULL DEFAULT NULL AFTER dia_semana";
    if ($conn->query($sql1)) {
        echo "✅ Columna 'fecha' agregada a tabla 'packs'.<br>";
    } else {
        echo "⚠️ " . $conn->error . "<br>";
    }
} catch (Exception $e) {
    echo "⚠️ " . $e->getMessage() . "<br>";
}

// 2. Agregar columna 'permite_inscripcion' a tabla packs
try {
    $sql2 = "ALTER TABLE packs ADD COLUMN permite_inscripcion TINYINT(1) DEFAULT 1 AFTER activo";
    if ($conn->query($sql2)) {
        echo "✅ Columna 'permite_inscripcion' agregada a tabla 'packs'.<br>";
    } else {
        echo "⚠️ " . $conn->error . "<br>";
    }
} catch (Exception $e) {
    echo "⚠️ " . $e->getMessage() . "<br>";
}

echo "<h3>Proceso finalizado.</h3>";
?>
