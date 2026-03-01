<?php
require_once "../db.php";

$sql = "CREATE TABLE IF NOT EXISTS disponibilidad_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    profesor_id INT NOT NULL,
    dia_semana INT NOT NULL, -- 0 (Domingo) a 6 (Sábado)
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    activo TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if ($conn->query($sql) === TRUE) {
    echo "Tabla disponibilidad_config creada correctamente\n";
} else {
    echo "Error creando tabla: " . $conn->error . "\n";
}

$conn->close();
?>
