<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); 
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once "../db.php";

// 1. SILENT SCHEMA FIX - Ensure table and columns exist BEFORE anything else
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
if (!function_exists('ensureColSave')) {
    function ensureColSave($conn, $col, $def) {
        $check = $conn->query("SHOW COLUMNS FROM cupones LIKE '$col'");
        if ($check && $check->num_rows == 0) {
            $conn->query("ALTER TABLE cupones ADD `$col` $def");
        }
    }
}
ensureColSave($conn, 'activo', "TINYINT(1) DEFAULT 1");
ensureColSave($conn, 'uso_actual', "INT DEFAULT 0");
ensureColSave($conn, 'pack_id', "INT NULL");
ensureColSave($conn, 'jugador_id', "INT NULL");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Auth check
$headers = getallheaders();
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

if (empty($auth)) {
    http_response_code(401);
    echo json_encode(["error" => "No Authorization header"]);
    exit;
}

$token_content = str_replace('Bearer ', '', $auth);
$expected = base64_encode("1|padel_academy");
if ($token_content !== $expected && $token_content !== "1|padel_academy") {
    // Permisos flexibles por ahora
}

try {
    $data = json_decode(file_get_contents("php://input"), true);

    if (!$data) {
        throw new Exception("Datos no recibidos o JSON inválido");
    }

    $id = $data['id'] ?? null;
    $entrenador_id = !empty($data['entrenador_id']) ? intval($data['entrenador_id']) : null;
    $codigo = strtoupper(trim($data['codigo'] ?? ''));
    $tipo_descuento = $data['tipo_descuento'] ?? 'porcentaje'; 
    $valor = floatval($data['valor'] ?? 0);
    $fecha_inicio = !empty($data['fecha_inicio']) ? $data['fecha_inicio'] : null;
    $fecha_fin = !empty($data['fecha_fin']) ? $data['fecha_fin'] : null;
    $jugador_id = !empty($data['jugador_id']) ? intval($data['jugador_id']) : null;
    $pack_id = !empty($data['pack_id']) ? intval($data['pack_id']) : null;
    $uso_maximo = !empty($data['uso_maximo']) ? intval($data['uso_maximo']) : null;

    if (!$entrenador_id || !$codigo) {
        throw new Exception("Entrenador ID y Código son requeridos");
    }

    if ($id) {
        // Update
        $sql = "UPDATE cupones SET codigo=?, tipo_descuento=?, valor=?, fecha_inicio=?, fecha_fin=?, jugador_id=?, pack_id=?, uso_maximo=? WHERE id=? AND entrenador_id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssdssiiiii", $codigo, $tipo_descuento, $valor, $fecha_inicio, $fecha_fin, $jugador_id, $pack_id, $uso_maximo, $id, $entrenador_id);
    } else {
        // Insert - Explicitly set activo = 1
        $sql = "INSERT INTO cupones (entrenador_id, codigo, tipo_descuento, valor, fecha_inicio, fecha_fin, jugador_id, pack_id, uso_maximo, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("issdssiii", $entrenador_id, $codigo, $tipo_descuento, $valor, $fecha_inicio, $fecha_fin, $jugador_id, $pack_id, $uso_maximo);
    }

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "id" => $id ? $id : $conn->insert_id]);
    } else {
        throw new Exception("Error al ejecutar la consulta: " . $stmt->error);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false, 
        "error" => $e->getMessage(),
        "trace" => $e->getTraceAsString()
    ]);
    
    // Log error to file
    error_log("Save Cupon Error: " . $e->getMessage() . " Data: " . json_encode($data));
}

$conn->close();
?>
