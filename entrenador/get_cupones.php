<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once "../db.php";

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$headers = getallheaders();
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

if (empty($auth)) {
    http_response_code(401);
    echo json_encode(["error" => "No Authorization header"]);
    exit;
}

// SILENT SCHEMA FIX - Ensure table and columns exist
$sql_create = "CREATE TABLE IF NOT EXISTS cupones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entrenador_id INT NOT NULL,
    codigo VARCHAR(50) NOT NULL,
    tipo_descuento ENUM('porcentaje', 'monto') NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    fecha_inicio DATE NULL,
    fecha_fin DATE NULL,
    jugador_id INT NULL,
    pack_id INT NULL,
    uso_maximo INT NULL,
    uso_actual INT DEFAULT 0,
    activo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (entrenador_id),
    INDEX (codigo)
)";
$conn->query($sql_create);

// Ensure columns if already existed
function ensureCol($conn, $col, $def) {
    if (!$conn->query("SHOW COLUMNS FROM cupones LIKE '$col'")->num_rows) {
        $conn->query("ALTER TABLE cupones ADD `$col` $def");
    }
}
ensureCol($conn, 'activo', "TINYINT(1) DEFAULT 1");
ensureCol($conn, 'uso_actual', "INT DEFAULT 0");
ensureCol($conn, 'pack_id', "INT NULL");
ensureCol($conn, 'jugador_id', "INT NULL");

$entrenador_id = $_GET['entrenador_id'] ?? null;
if (!$entrenador_id) {
    echo json_encode(["error" => "entrenador_id requerido"]);
    exit;
}

try {
    $sql = "SELECT c.*, u.nombre as jugador_nombre, p.nombre as pack_nombre 
            FROM cupones c
            LEFT JOIN usuarios u ON c.jugador_id = u.id
            LEFT JOIN packs p ON c.pack_id = p.id
            WHERE c.entrenador_id = ? AND c.activo = 1
            ORDER BY c.created_at DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $entrenador_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $cupones = [];
    while ($row = $result->fetch_assoc()) {
        $cupones[] = $row;
    }

    echo json_encode($cupones);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
$conn->close();
?>
