<?php
require_once "../db.php";

function columnExists($conn, $table, $column) {
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && $result->num_rows > 0;
}

$changes = [
    ['usuarios', 'descripcion', "ALTER TABLE usuarios ADD COLUMN descripcion TEXT AFTER categoria"],
    ['reservas', 'created_at', "ALTER TABLE reservas ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"],
    ['pack_jugadores', 'reserva_id', "ALTER TABLE pack_jugadores ADD COLUMN reserva_id INT DEFAULT NULL"]
];

// Crear tabla de configuración de horarios si no existe
$conn->query("CREATE TABLE IF NOT EXISTS entrenador_horarios_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entrenador_id INT NOT NULL,
    dia_semana TINYINT NOT NULL COMMENT '0=Dom, 1=Lun, ..., 6=Sab',
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    duracion_bloque INT DEFAULT 60 COMMENT 'minutos',
    INDEX (entrenador_id),
    INDEX (dia_semana)
)");

echo "<h2>Actualizando esquema de base de datos...</h2>";

foreach ($changes as $change) {
    list($table, $column, $sql) = $change;
    if (!columnExists($conn, $table, $column)) {
        if ($conn->query($sql)) {
            echo "✅ Columna '$column' añadida a tabla '$table'.<br>";
        } else {
            echo "❌ Error al añadir '$column' a '$table': " . $conn->error . "<br>";
        }
    } else {
        echo "ℹ️ La columna '$column' ya existe en '$table'.<br>";
    }
}

echo "<br><b>Proceso finalizado.</b>";
?>
