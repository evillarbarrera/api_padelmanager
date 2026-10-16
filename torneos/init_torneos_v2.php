<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

// Función ayudante para añadir columnas de forma segura
function addColumnSafe($conn, $table, $column, $definition) {
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($res && $res->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

// 1. Crear tabla principal si no existe
$sql = "CREATE TABLE IF NOT EXISTS torneos_v2 (
    id INT AUTO_INCREMENT PRIMARY KEY,
    club_id INT NOT NULL,
    creator_id INT NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    descripcion TEXT,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    tipo VARCHAR(50) DEFAULT 'Grupos + Playoffs',
    formato_grupos INT DEFAULT 4,
    formato_sets VARCHAR(50) DEFAULT 'Full Sets',
    poster_url VARCHAR(255) DEFAULT NULL,
    inscripciones_abiertas BOOLEAN DEFAULT FALSE,
    estado ENUM('Abierto', 'En Curso', 'Finalizado', 'Cerrado') DEFAULT 'Abierto',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

if (!$conn->query($sql)) {
    die(json_encode(["success" => false, "error" => "Error creando tabla principal: " . $conn->error]));
}

// 2. Reparación segura de columnas (Compatible con versiones antiguas de MySQL)
addColumnSafe($conn, 'torneos_v2', 'creator_id', 'INT NOT NULL AFTER club_id');
addColumnSafe($conn, 'torneos_v2', 'poster_url', "VARCHAR(255) DEFAULT NULL AFTER formato_sets");
addColumnSafe($conn, 'torneos_v2', 'inscripciones_abiertas', "BOOLEAN DEFAULT FALSE AFTER poster_url");
addColumnSafe($conn, 'torneos_v2', 'formato_grupos', "INT DEFAULT 4 AFTER tipo");
addColumnSafe($conn, 'torneos_v2', 'formato_sets', "VARCHAR(50) DEFAULT 'Full Sets' AFTER formato_grupos");

// 3. Crear el resto de tablas
$otras_tablas = [
    "torneo_categorias" => "CREATE TABLE IF NOT EXISTS torneo_categorias (
        id INT AUTO_INCREMENT PRIMARY KEY,
        torneo_id INT NOT NULL,
        nombre VARCHAR(50) NOT NULL,
        max_parejas INT DEFAULT 16,
        puntos_repartir INT DEFAULT 0,
        estado ENUM('Abierto', 'Grupos', 'Playoffs', 'Finalizado') DEFAULT 'Abierto'
    )",
    "torneo_parejas" => "CREATE TABLE IF NOT EXISTS torneo_parejas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre_pareja VARCHAR(255),
        jugador1_id INT NULL,
        jugador2_id INT NULL,
        jugador1_nombre_manual VARCHAR(255) NULL,
        jugador2_nombre_manual VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "torneo_inscripciones" => "CREATE TABLE IF NOT EXISTS torneo_inscripciones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        categoria_id INT NOT NULL,
        pareja_id INT NOT NULL,
        validado BOOLEAN DEFAULT FALSE,
        es_semilla BOOLEAN DEFAULT FALSE,
        nro_siembra INT NULL,
        ranking_puntos INT DEFAULT 0
    )"
];

foreach ($otras_tablas as $name => $query) {
    if (!$conn->query($query)) {
        die(json_encode(["success" => false, "error" => "Error en tabla $name: " . $conn->error]));
    }
}

echo json_encode(["success" => true, "message" => "Base de Datos V2 sincronizada con éxito (Modo Compatible)."]);
?>
