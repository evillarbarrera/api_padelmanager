<?php
require_once "../db.php";

$sql = "CREATE TABLE IF NOT EXISTS tips_diarios_ia ( id INT AUTO_INCREMENT PRIMARY KEY, fecha DATE NOT NULL, titulo VARCHAR(255) NOT NULL, mensaje VARCHAR(1000) NOT NULL, UNIQUE KEY unique_fecha (fecha) );";

if ($conn->query($sql)) {
    echo "Tabla tips_diarios_ia creada correctamente.\\n";
} else {
    echo "Error: " . $conn->error . "\\n";
}
?>
