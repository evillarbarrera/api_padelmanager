<?php
require_once "../db.php";

echo "--- Iniciando migración de tabla recordatorios_programados ---\n";

// 1. Cambiar fecha_programada a DATETIME si es DATE
$res = $conn->query("DESCRIBE recordatorios_programados");
$colInfo = [];
while($row = $res->fetch_assoc()) {
    if ($row['Field'] === 'fecha_programada') {
        $colInfo = $row;
        break;
    }
}

if ($colInfo && strpos(strtolower($colInfo['Type']), 'datetime') === false) {
    echo "Actualizando columna fecha_programada a DATETIME...\n";
    $conn->query("ALTER TABLE recordatorios_programados MODIFY COLUMN fecha_programada DATETIME");
} else {
    echo "La columna fecha_programada ya es DATETIME o similar.\n";
}

// 2. Asegurar que existe enviado_at (opcional pero recomendado)
$res = $conn->query("SHOW COLUMNS FROM recordatorios_programados LIKE 'enviado_at'");
if ($res->num_rows === 0) {
    echo "Agregando columna enviado_at...\n";
    $conn->query("ALTER TABLE recordatorios_programados ADD COLUMN enviado_at DATETIME NULL AFTER enviado");
}

echo "Migración completada.\n";
?>
