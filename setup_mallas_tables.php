<?php
require_once "db.php";

$sql = "
CREATE TABLE IF NOT EXISTS mallas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entrenador_id INT NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    nivel VARCHAR(50),
    publico VARCHAR(50),
    created_at DATETIME
);

CREATE TABLE IF NOT EXISTS clases_malla (
    id INT AUTO_INCREMENT PRIMARY KEY,
    malla_id INT NOT NULL,
    orden INT NOT NULL,
    titulo VARCHAR(255) NOT NULL,
    objetivo TEXT,
    calentamiento TEXT,
    parte_tecnica TEXT,
    drills TEXT,
    juego TEXT,
    recursos VARCHAR(255),
    FOREIGN KEY (malla_id) REFERENCES mallas(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS alumno_malla_seguimiento (
    id INT AUTO_INCREMENT PRIMARY KEY,
    jugador_id INT NOT NULL,
    malla_id INT NOT NULL,
    entrenador_id INT NOT NULL,
    estado ENUM('activo', 'completado', 'pausado', 'cancelado') DEFAULT 'activo',
    clase_actual_orden INT DEFAULT 1,
    fecha_inicio DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS alumno_asistencia (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reserva_id INT,
    jugador_id INT NOT NULL,
    alumno_malla_id INT,
    clase_malla_id INT,
    estado_asistencia ENUM('pendiente', 'presente', 'ausente', 'justificado') DEFAULT 'pendiente',
    feedback_coach TEXT,
    objetivos_logrados JSON,
    created_at DATETIME
);
";

// Split and execute Multiple queries
$queries = explode(';', $sql);
foreach($queries as $q) {
    if(trim($q)) {
        if($conn->query($q)) {
            echo "Query OK: " . substr($q, 0, 50) . "...<br>";
        } else {
            echo "Error: " . $conn->error . "<br>";
        }
    }
}

echo "Setup Complete.";
?>
