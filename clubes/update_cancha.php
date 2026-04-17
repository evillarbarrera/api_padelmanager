<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$headers = getallheaders();
require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    http_response_code(400);
    echo json_encode(["error" => "Datos inválidos"]);
    exit;
}

$id = $data['id'] ?? 0;
$nombre = $data['nombre'] ?? '';
$tipo = $data['tipo'] ?? 'Outdoor';
$superficie = $data['superficie'] ?? 'Césped Sintético';

// Nuevos precios
$p60 = $data['precio_60'] ?? 0;
$p90 = $data['precio_90'] ?? 0;
$p120 = $data['precio_120'] ?? 0;

if (empty($id) || empty($nombre)) {
    http_response_code(400);
    echo json_encode(["error" => "ID y nombre son obligatorios"]);
    exit;
}

$sql = "UPDATE canchas SET nombre = ?, tipo = ?, superficie = ?, precio_60 = ?, precio_90 = ?, precio_120 = ? WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sssiiii", $nombre, $tipo, $superficie, $p60, $p90, $p120, $id);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Cancha actualizada"]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al actualizar cancha: " . $conn->error]);
}
?>
