<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, X-Authorization, Content-Type");
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../auth/auth_helper.php";
$tokenUserId = validateToken();
if (!$tokenUserId) {
    sendUnauthorized();
}

header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, X-Authorization, Content-Type");
    http_response_code(200);
    exit;
}

// Authorization


require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$reserva_id = $data['id'] ?? null;

if (!$reserva_id) {
    http_response_code(400);
    echo json_encode(["error" => "ID de reserva no proporcionado"]);
    exit;
}

try {
    if (!$conn) {
        throw new Exception("No hay conexión con la base de datos");
    }

    // Actualizar el estado a cancelado
    $stmt = $conn->prepare("UPDATE reservas SET estado = 'cancelado' WHERE id = ?");
    $stmt->bind_param("i", $reserva_id);
    
    if ($stmt->execute()) {
        echo json_encode([
            "success" => true,
            "message" => "Reserva cancelada correctamente. Créditos liberados."
        ]);
    } else {
        throw new Exception("No se pudo actualizar la reserva");
    }

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(["error" => $e->getMessage()]);
}
?>
