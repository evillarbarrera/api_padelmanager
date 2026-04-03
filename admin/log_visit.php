<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

// 1. Asegurar que la tabla existe y tiene las columnas necesarias (Auto-setup)
$sql_table = "CREATE TABLE IF NOT EXISTS web_analytics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pagina VARCHAR(150) NOT NULL,
    usuario_id INT NULL,
    rol VARCHAR(50) NULL,
    dispositivo VARCHAR(50) DEFAULT 'PC',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($sql_table);

// Asegurar que existe la columna 'dispositivo' si la tabla ya existía
$conn->query("ALTER TABLE web_analytics ADD COLUMN IF NOT EXISTS dispositivo VARCHAR(50) DEFAULT 'PC' AFTER rol");

// 2. Procesar el log
$data = json_decode(file_get_contents("php://input"), true);

if (isset($data['pagina'])) {
    $pagina = $conn->real_escape_string($data['pagina']);
    $usuario_id = isset($data['usuario_id']) ? (int)$data['usuario_id'] : 'NULL';
    $rol = isset($data['rol']) ? "'" . $conn->real_escape_string($data['rol']) . "'" : 'NULL';
    $dispositivo = isset($data['dispositivo']) ? "'" . $conn->real_escape_string($data['dispositivo']) . "'" : "'PC'";

    $sql_insert = "INSERT INTO web_analytics (pagina, usuario_id, rol, dispositivo) VALUES ('$pagina', $usuario_id, $rol, $dispositivo)";
    
    if ($conn->query($sql_insert)) {
        echo json_encode(["success" => true, "message" => "Visit logged"]);
    } else {
        echo json_encode(["success" => false, "error" => $conn->error]);
    }
} else {
    echo json_encode(["success" => false, "error" => "No page provided"]);
}
?>
