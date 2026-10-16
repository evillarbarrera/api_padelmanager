<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$usuario_id = $data['usuario_id'] ?? null;
$club_id = $data['club_id'] ?? null;

if (!$usuario_id || !$club_id) {
    http_response_code(400);
    echo json_encode(["error" => "ID de usuario y club son obligatorios"]);
    exit;
}

// Seguimos el patrón de Soft Delete de la aplicación usando la columna 'activo'
$sql = "UPDATE usuarios_clubes SET activo = 0 WHERE usuario_id = ? AND club_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $usuario_id, $club_id);

if ($stmt->execute()) {
    echo json_encode(["success" => true]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al revocar acceso: " . $conn->error]);
}
?>
