<?php
require_once "db.php";

function ensureColumn($conn, $table, $column, $definition) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($check && $check->num_rows == 0) {
        $q = "ALTER TABLE `$table` ADD `$column` $definition";
        if ($conn->query($q)) {
            echo "Columna '$column' añadida a '$table'.\n";
        } else {
            echo "Error al añadir '$column' a '$table': " . $conn->error . "\n";
        }
    } else {
        echo "Columna '$column' ya existe en '$table'.\n";
    }
}

echo "--- Verificando esquema de 'reservas' ---\n";
ensureColumn($conn, 'reservas', 'malla_id', "INT NULL DEFAULT NULL AFTER club_id");
ensureColumn($conn, 'reservas', 'clase_id', "INT NULL DEFAULT NULL AFTER malla_id");
ensureColumn($conn, 'reservas', 'clase_titulo', "VARCHAR(255) NULL DEFAULT NULL AFTER clase_id");
ensureColumn($conn, 'reservas', 'cantidad_personas', "INT DEFAULT 1 AFTER tipo");
ensureColumn($conn, 'reservas', 'serie_id', "VARCHAR(100) NULL AFTER estado");

echo "--- Verificando esquema de 'notificaciones' ---\n";
ensureColumn($conn, 'notificaciones', 'user_id', "INT");
ensureColumn($conn, 'notificaciones', 'titulo', "VARCHAR(255)");
ensureColumn($conn, 'notificaciones', 'mensaje', "TEXT");
ensureColumn($conn, 'notificaciones', 'tipo', "VARCHAR(50)");
ensureColumn($conn, 'notificaciones', 'fecha_referencia', "DATETIME NULL");
ensureColumn($conn, 'notificaciones', 'leida', "TINYINT DEFAULT 0");

echo "--- Verificando esquema de 'recordatorios_programados' ---\n";
$checkTable = $conn->query("SHOW TABLES LIKE 'recordatorios_programados'");
if ($checkTable && $checkTable->num_rows == 0) {
    $conn->query("CREATE TABLE recordatorios_programados (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        titulo VARCHAR(255),
        mensaje TEXT,
        tipo VARCHAR(50),
        fecha_programada DATETIME,
        enviado TINYINT DEFAULT 0,
        fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "Tabla 'recordatorios_programados' creada.\n";
} else {
    echo "Tabla 'recordatorios_programados' ya existe.\n";
}

echo "--- Esquema verificado ---\n";
?>
