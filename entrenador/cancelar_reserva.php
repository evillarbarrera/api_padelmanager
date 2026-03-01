<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$headers = getallheaders();
$auth = $headers['Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

if ($auth !== 'Bearer ' . base64_encode("1|padel_academy")) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$reserva_id = $data['reserva_id'] ?? null;

if (!$reserva_id) {
    http_response_code(400);
    echo json_encode(["error" => "reserva_id es obligatorio"]);
    exit;
}

$sql = "UPDATE reservas SET estado = 'cancelado' WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $reserva_id);

if ($stmt->execute()) {
    echo json_encode(["ok" => true, "message" => "Reserva cancelada correctamente"]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al cancelar la reserva: " . $conn->error]);
}

$conn->close();
