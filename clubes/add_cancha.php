<?php
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
if (!$data) {
    http_response_code(400);
    echo json_encode(["error" => "Datos inválidos"]);
    exit;
}

$club_id = $data['club_id'] ?? 0;
$nombre = $data['nombre'] ?? '';
$tipo = $data['tipo'] ?? 'Outdoor';
$superficie = $data['superficie'] ?? 'Césped Sintético';
$precio_hora = $data['precio_hora'] ?? 0;

if (empty($club_id) || empty($nombre)) {
    http_response_code(400);
    echo json_encode(["error" => "club_id y nombre son obligatorios"]);
    exit;
}

$sql = "INSERT INTO canchas (club_id, nombre, tipo, superficie, precio_hora) VALUES (?, ?, ?, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("isssd", $club_id, $nombre, $tipo, $superficie, $precio_hora);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "id" => $conn->insert_id]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al agregar cancha: " . $conn->error]);
}
?>
