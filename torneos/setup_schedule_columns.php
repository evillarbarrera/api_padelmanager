<?php
require_once "../db.php";

// Agregar columnas para programación si no existen
$alterSql = "ALTER TABLE torneo_partidos_v2 
             ADD COLUMN fecha_hora_inicio DATETIME NULL,
             ADD COLUMN cancha_id INT NULL,
             ADD COLUMN duracion_minutos INT DEFAULT 90";

if ($conn->query($alterSql)) {
    echo "Columnas agregadas correctamente.";
} else {
    // Es probable que ya existan, ignoramos error pero mostramos
    echo "Info: " . $conn->error;
}
?>
