<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // Prevenir que errores de PHP rompan el formato JSON

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

try {
    // 1. Asegurar que la tabla existe (Auto-setup)
    $sql_table = "CREATE TABLE IF NOT EXISTS web_analytics (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pagina VARCHAR(150) NOT NULL,
        usuario_id INT NULL,
        rol VARCHAR(50) NULL,
        dispositivo VARCHAR(50) DEFAULT 'PC',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    @$conn->query($sql_table);

    // Asegurar compatibilidad de la columna 'dispositivo' sin sintaxis incompatible
    $checkColumn = @$conn->query("SHOW COLUMNS FROM web_analytics LIKE 'dispositivo'");
    if ($checkColumn && $checkColumn->num_rows == 0) {
        @$conn->query("ALTER TABLE web_analytics ADD COLUMN dispositivo VARCHAR(50) DEFAULT 'PC' AFTER rol");
    }

    // 2. Procesar el log mediante consulta preparada
    $data = json_decode(file_get_contents("php://input"), true);

    if (isset($data['pagina']) && !empty($data['pagina'])) {
        $pagina = trim($data['pagina']);
        $usuario_id = !empty($data['usuario_id']) ? (int)$data['usuario_id'] : null;
        $rol = !empty($data['rol']) ? trim($data['rol']) : null;
        $dispositivo = !empty($data['dispositivo']) ? trim($data['dispositivo']) : 'PC';

        $stmt = $conn->prepare("INSERT INTO web_analytics (pagina, usuario_id, rol, dispositivo) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("siss", $pagina, $usuario_id, $rol, $dispositivo);
            if ($stmt->execute()) {
                echo json_encode(["success" => true, "message" => "Visit logged"]);
                exit;
            }
        }
        
        echo json_encode(["success" => false, "error" => $conn->error ?? "Error al insertar registro de visita"]);
    } else {
        echo json_encode(["success" => false, "error" => "No page provided"]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
