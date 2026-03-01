<?php
header("Content-Type: application/json");
require_once "../db.php";

// Simple migration script to initialize the new tournament module tables (v2)

$queries = [
    "CREATE TABLE IF NOT EXISTS torneos_v2 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        club_id INT NOT NULL,
        nombre VARCHAR(100) NOT NULL,
        descripcion TEXT,
        fecha_inicio DATE,
        fecha_fin DATE,
        estado ENUM('Inscripción', 'En Curso', 'Finalizado') DEFAULT 'Inscripción',
        tipo ENUM('Grupos + Playoffs', 'Eliminación Directa') DEFAULT 'Grupos + Playoffs',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS torneo_categorias (
        id INT AUTO_INCREMENT PRIMARY KEY,
        torneo_id INT NOT NULL,
        nombre VARCHAR(50) NOT NULL,
        precio DECIMAL(10,2) DEFAULT 0,
        max_parejas INT DEFAULT 16,
        puntos_repartir INT DEFAULT 0,
        FOREIGN KEY (torneo_id) REFERENCES torneos_v2(id) ON DELETE CASCADE
    )",
    "CREATE TABLE IF NOT EXISTS torneo_parejas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        jugador1_id INT NOT NULL,
        jugador2_id INT NULL, -- NULL if not yet complete
        nombre_pareja VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS torneo_inscripciones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        categoria_id INT NOT NULL,
        pareja_id INT NOT NULL,
        pagado BOOLEAN DEFAULT FALSE,
        validado BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (categoria_id) REFERENCES torneo_categorias(id) ON DELETE CASCADE,
        FOREIGN KEY (pareja_id) REFERENCES torneo_parejas(id) ON DELETE CASCADE
    )",
    "CREATE TABLE IF NOT EXISTS torneo_grupos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        categoria_id INT NOT NULL,
        nombre VARCHAR(20) NOT NULL, -- 'Grupo A', 'Grupo B'
        FOREIGN KEY (categoria_id) REFERENCES torneo_categorias(id) ON DELETE CASCADE
    )",
    "CREATE TABLE IF NOT EXISTS torneo_grupo_parejas (
        grupo_id INT NOT NULL,
        pareja_id INT NOT NULL,
        puntos INT DEFAULT 0,
        pj INT DEFAULT 0,
        pg INT DEFAULT 0,
        pp INT DEFAULT 0,
        sf INT DEFAULT 0, -- Sets Favor
        sc INT DEFAULT 0, -- Sets Contra
        gf INT DEFAULT 0, -- Games Favor
        gc INT DEFAULT 0, -- Games Contra
        PRIMARY KEY (grupo_id, pareja_id),
        FOREIGN KEY (grupo_id) REFERENCES torneo_grupos(id) ON DELETE CASCADE,
        FOREIGN KEY (pareja_id) REFERENCES torneo_parejas(id) ON DELETE CASCADE
    )",
    "CREATE TABLE IF NOT EXISTS torneo_partidos_v2 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        categoria_id INT NOT NULL,
        grupo_id INT NULL, -- NULL for playoffs
        ronda VARCHAR(50) NULL, -- 'Octavos', 'Cuartos', etc.
        pareja1_id INT NOT NULL,
        pareja2_id INT NOT NULL,
        ganador_id INT NULL,
        resultado_json TEXT, -- '[{\"p1\":6, \"p2\":4}, {\"p1\":3, \"p2\":6}]'
        fecha_hora DATETIME,
        cancha_id INT,
        estado ENUM('Pendiente', 'En Juego', 'Finalizado') DEFAULT 'Pendiente',
        FOREIGN KEY (categoria_id) REFERENCES torneo_categorias(id) ON DELETE CASCADE
    )"
];

$results = [];
foreach ($queries as $sql) {
    if ($conn->query($sql)) {
        $results[] = ["status" => "success", "query" => substr($sql, 0, 50) . "..."];
    } else {
        $results[] = ["status" => "error", "message" => $conn->error, "query" => $sql];
    }
}

echo json_encode(["migration_results" => $results]);
?>
