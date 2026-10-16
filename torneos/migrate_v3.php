<?php
require_once "../db.php";

$sql = "
-- Main Tournament categories configuration
CREATE TABLE IF NOT EXISTS torneo_categorias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    torneo_id INT NOT NULL,
    nombre VARCHAR(50) NOT NULL,
    max_parejas INT DEFAULT 16,
    estado ENUM('Abierto', 'Grupos', 'Playoffs', 'Finalizado') DEFAULT 'Abierto'
);

-- Group definition
CREATE TABLE IF NOT EXISTS torneo_grupos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    categoria_id INT NOT NULL,
    nombre VARCHAR(10) NOT NULL,
    posicion_cuadro INT
);

-- Playoff Match results V2 (expanded for 16-seeds)
CREATE TABLE IF NOT EXISTS torneo_partidos_cuadro (
    id INT AUTO_INCREMENT PRIMARY KEY,
    categoria_id INT NOT NULL,
    nivel INT NOT NULL, 
    lado_cuadro ENUM('izq', 'der') NOT NULL,
    posicion INT NOT NULL, 
    siguiente_partido_id INT NULL,
    pareja1_id INT NULL,
    pareja2_id INT NULL,
    puntos_t1 INT,
    puntos_t2 INT,
    resultado_json TEXT,
    finalizado BOOLEAN DEFAULT FALSE,
    ganador_id INT NULL,
    fecha DATE,
    hora TIME,
    cancha_id INT
);
";

if ($conn->multi_query($sql)) {
    do {
        if ($res = $conn->store_result()) {
            $res->free();
        }
    } while ($conn->more_results() && $conn->next_result());
    echo "Sincronización de tablas V3 completada exitosamente.\n";
} else {
    echo "Error ejecutando migración: " . $conn->error . "\n";
}
?>
