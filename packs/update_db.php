<?php
// Script para actualizar la estructura de la base de datos
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once "../db.php";

echo "Agregando columnas rango_horario a la tabla packs...<br>";

// Intentar agregar rango_horario_inicio
try {
    $sql1 = "ALTER TABLE packs ADD COLUMN rango_horario_inicio TIME NULL DEFAULT NULL";
    $conn->query($sql1);
    echo "Columna rango_horario_inicio procesada.<br>";
} catch (Exception $e) {
    echo "Info: " . $e->getMessage() . "<br>";
}

// Intentar agregar rango_horario_fin
try {
    $sql2 = "ALTER TABLE packs ADD COLUMN rango_horario_fin TIME NULL DEFAULT NULL";
    $conn->query($sql2);
    echo "Columna rango_horario_fin procesada.<br>";
} catch (Exception $e) {
    echo "Info: " . $e->getMessage() . "<br>";
}

echo "Proceso finalizado. Verifica tu base de datos.";
?>
