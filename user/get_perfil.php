<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); 
ini_set('log_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit;
}

require_once "../db.php";
require_once "../auth/auth_helper.php";

$userId = validateToken();
if (!$userId) {
    sendUnauthorized();
}

$user_id = $_GET['user_id'] ?? 0;

if (!$user_id) {
    http_response_code(400);
    echo json_encode(["error" => "user_id is required"]);
    exit;
}

try {
    // Check columns to avoid 500 errors if some are missing
    $columns = [];
    $resCols = $conn->query("SHOW COLUMNS FROM usuarios");
    while($c = $resCols->fetch_assoc()) { $columns[] = $c['Field']; }

    $selectable = ["id", "nombre", "usuario", "rol", "foto", "foto_perfil", "instagram", "facebook", "telefono", "categoria", "descripcion", "created_at", "google_id", "proveedor"];
    
    // Add banking columns if they exist
    $bankCols = ["banco_titular", "banco_rut", "banco_nombre", "banco_tipo_cuenta", "banco_numero_cuenta", "mp_collector_id"];
    foreach($bankCols as $bc) {
        if (in_array($bc, $columns)) {
            $selectable[] = $bc;
        }
    }

    $sqlUser = "SELECT " . implode(", ", $selectable) . " FROM usuarios WHERE id = ?";
    $stmtUser = $conn->prepare($sqlUser);
    $stmtUser->bind_param("i", $user_id);
    $stmtUser->execute();
    $resUser = $stmtUser->get_result();
    $userData = $resUser->fetch_assoc();

    if (!$userData) {
        http_response_code(404);
        echo json_encode(["error" => "User not found"]);
        exit;
    }

    // 2. Fetch address data
    $addrData = null;
    $resTableDir = $conn->query("SHOW TABLES LIKE 'direcciones'");
    if ($resTableDir && $resTableDir->num_rows > 0) {
        $sqlAddr = "SELECT region, comuna, calle, numero_casa, referencia FROM direcciones WHERE usuario_id = ?";
        $stmtAddr = $conn->prepare($sqlAddr);
        $stmtAddr->bind_param("i", $user_id);
        $stmtAddr->execute();
        $resAddr = $stmtAddr->get_result();
        $addrData = $resAddr->fetch_assoc();
    }

    echo json_encode([
        "success" => true,
        "user" => $userData,
        "direccion" => $addrData ?? null
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
