<?php
header("Access-Control-Allow-Origin: http://localhost:8100");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Validar token
$headers = getallheaders();
$auth = $headers['Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if ($auth !== 'Bearer ' . base64_encode("1|padel_academy")) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$id = $data['id'] ?? $data['pack_id'] ?? null;

if (!$id) {
    http_response_code(400);
    echo json_encode(["error" => "Falta id"]);
    exit;
}

// Marcar pack como inactivo
$stmt = $conn->prepare("UPDATE packs SET activo = 0 WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    echo json_encode(["success" => true]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al eliminar pack"]);
}
