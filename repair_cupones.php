<?php
header("Content-Type: application/json");
require_once "db.php";

// Function to ensure column exists
function ensureColumn($conn, $table, $column, $definition) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($check && $check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD `$column` $definition");
        return "Added $column to $table";
    }
    return "$column already exists in $table";
}

$results = [];

// 1. Create table if not exists
$sql_create = "CREATE TABLE IF NOT EXISTS cupones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entrenador_id INT NOT NULL,
    codigo VARCHAR(50) NOT NULL,
    tipo_descuento ENUM('porcentaje', 'monto') NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    fecha_inicio DATE NULL,
    fecha_fin DATE NULL,
    jugador_id INT NULL,
    pack_id INT NULL,
    uso_maximo INT NULL,
    uso_actual INT DEFAULT 0,
    activo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (entrenador_id),
    INDEX (codigo)
)";

if ($conn->query($sql_create)) {
    $results[] = "Table cupones ready";
} else {
    $results[] = "Error creating table: " . $conn->error;
}

// 2. Ensure columns (in case table already existed but incomplete)
$results[] = ensureColumn($conn, 'cupones', 'uso_actual', "INT DEFAULT 0");
$results[] = ensureColumn($conn, 'cupones', 'activo', "TINYINT(1) DEFAULT 1");
$results[] = ensureColumn($conn, 'cupones', 'entrenador_id', "INT");
$results[] = ensureColumn($conn, 'cupones', 'jugador_id', "INT NULL");
$results[] = ensureColumn($conn, 'cupones', 'pack_id', "INT NULL");

// 3. Fix existing rows that might have activo = 0 or NULL
$conn->query("UPDATE cupones SET activo = 1 WHERE activo IS NULL OR activo = 0");

echo json_encode($results);
?>
