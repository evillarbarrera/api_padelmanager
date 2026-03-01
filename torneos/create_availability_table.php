<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Display: *"); // Just in case

include_once '../db.php';

$sql = "CREATE TABLE IF NOT EXISTS torneo_horarios_disponibilidad (
    id INT AUTO_INCREMENT PRIMARY KEY,
    torneo_id INT NOT NULL,
    fecha DATE NOT NULL,
    hora INT NOT NULL COMMENT 'Hour in 24h format (0-23)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_availability (torneo_id, fecha, hora),
    INDEX idx_torneo_fecha (torneo_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if ($conn->query($sql) === TRUE) {
    echo json_encode(["success" => true, "message" => "Table created successfully"]);
} else {
    echo json_encode(["success" => false, "message" => "Error creating table: " . $conn->error]);
}

$conn->close();
?>
