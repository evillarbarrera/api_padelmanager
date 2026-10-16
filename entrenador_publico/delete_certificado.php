<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";
require_once "../auth/auth_helper.php";

$userId = validateToken();
if (!$userId) {
    sendUnauthorized("Token inválido");
}

$input = json_decode(file_get_contents("php://input"), true);
$certId = intval($input['id'] ?? ($_POST['id'] ?? 0));

if ($certId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "ID de certificado inválido"]);
    exit;
}

$stmt = $conn->prepare("UPDATE entrenador_certificados SET activo = 0 WHERE id = ? AND entrenador_id = ?");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error en la base de datos: " . $conn->error]);
    exit;
}
$stmt->bind_param("ii", $certId, $userId);
$stmt->execute();

if ($stmt->affected_rows > 0) {
    echo json_encode(["status" => "success", "message" => "Certificado eliminado exitosamente"]);
} else {
    echo json_encode(["status" => "error", "message" => "No se encontró el certificado o ya fue eliminado"]);
}
$stmt->close();
