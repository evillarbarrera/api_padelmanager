<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
require_once "../db.php";

$results = [];

// 1. Agregar columna categoria a torneos_americanos
$check = $conn->query("SHOW COLUMNS FROM `torneos_americanos` LIKE 'categoria'");
if ($check->num_rows == 0) {
    if ($conn->query("ALTER TABLE torneos_americanos ADD COLUMN categoria VARCHAR(50) DEFAULT 'Cuarta'")) {
        $results[] = "Columna 'categoria' añadida a torneos_americanos.";
    } else {
        $results[] = "Error al añadir 'categoria': " . $conn->error;
    }
} else {
    $results[] = "La columna 'categoria' ya existe en torneos_americanos.";
}

// 2. Crear tabla de ranking por categorías para mayor flexibilidad
$sql = "CREATE TABLE IF NOT EXISTS ranking_categorias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    categoria VARCHAR(50) NOT NULL,
    puntos INT DEFAULT 0,
    UNIQUE KEY (usuario_id, categoria)
)";

if ($conn->query($sql)) {
    $results[] = "Tabla ranking_categorias lista.";
} else {
    $results[] = "Error al crear ranking_categorias: " . $conn->error;
}

echo json_encode(["results" => $results]);
?>
