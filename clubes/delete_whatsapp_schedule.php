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

$data = json_decode(file_get_contents('php://input'), true);

$id      = $data['id'] ?? 0;
$club_id = $data['club_id'] ?? 0;

if (!$id || !$club_id) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "id y club_id son requeridos"]);
    exit;
}

$stmt = $conn->prepare("DELETE FROM club_whatsapp_programacion WHERE id = ? AND club_id = ?");
$stmt->bind_param("ii", $id, $club_id);

if ($stmt->execute()) {
    echo json_encode(["success" => true]);
} else {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $conn->error]);
}
?>
