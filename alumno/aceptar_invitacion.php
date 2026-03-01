<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$token = $data['token'] ?? null;

if (!$token) {
    http_response_code(400);
    echo json_encode(["error" => "Token no proporcionado"]);
    exit;
}

// 1. Validar token y estado
$sql = "SELECT id FROM pack_jugadores_adicionales WHERE token = ? AND estado = 'pendiente'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $token);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();

if (!$res) {
    http_response_code(404);
    echo json_encode(["error" => "La invitación ya no está disponible o ya fue aceptada."]);
    exit;
}

$invitacion_id = $res['id'];

// 2. Aceptar invitación
$sqlUpdate = "UPDATE pack_jugadores_adicionales SET estado = 'aceptado', fecha_asignacion = NOW() WHERE id = ?";
$stmtU = $conn->prepare($sqlUpdate);
$stmtU->bind_param("i", $invitacion_id);

if ($stmtU->execute()) {
    echo json_encode(["success" => true, "message" => "¡Invitación aceptada! Ahora compartes este pack."]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al aceptar invitación: " . $conn->error]);
}
?>
