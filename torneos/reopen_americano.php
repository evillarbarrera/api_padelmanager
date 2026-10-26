<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../auth/auth_helper.php";
$userId = validateToken();
if (!$userId) {
    sendUnauthorized();
}

require_once "../db.php";

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true) ?: [];

$torneo_id = (int)($data['torneo_id'] ?? $data['id'] ?? $_POST['torneo_id'] ?? $_POST['id'] ?? $_GET['torneo_id'] ?? $_GET['id'] ?? 0);

if (!$torneo_id) {
    http_response_code(400);
    echo json_encode(["error" => "ID de torneo requerido"]);
    exit;
}

// Actualizar a Abierto y actualizar fecha a hoy si era anterior para mantenerlo activo
$today = date('Y-m-d');
$stmt = $conn->prepare("UPDATE torneos_americanos SET estado = 'Abierto', fecha = CASE WHEN fecha < ? THEN ? ELSE fecha END WHERE id = ?");
$stmt->bind_param("ssi", $today, $today, $torneo_id);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "mensaje" => "Torneo reabierto exitosamente"]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al reabrir torneo: " . $conn->error]);
}
?>
