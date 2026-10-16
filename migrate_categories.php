<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
require_once "db.php";

$sql = "CREATE TABLE IF NOT EXISTS entrenamiento_video_categorias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    jugador_id INT NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (jugador_id, nombre)
)";

if ($conn->query($sql)) {
    echo json_encode(["success" => true, "message" => "Tabla de categorías creada correctamente"]);
} else {
    echo json_encode(["success" => false, "error" => $conn->error]);
}
?>
