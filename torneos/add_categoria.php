<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$torneo_id = $data['torneo_id'] ?? 0;
$nombre = $data['nombre'] ?? '';
$max_parejas = $data['max_parejas'] ?? 16;
$puntos = $data['puntos_repartir'] ?? 0;

if (!$torneo_id || !$nombre) {
    http_response_code(400);
    echo json_encode(["error" => "Faltan datos obligatorios (torneo_id, nombre)"]);
    exit;
}

$sql = "INSERT INTO torneo_categorias (torneo_id, nombre, max_parejas, puntos_repartir) VALUES (?, ?, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("isii", $torneo_id, $nombre, $max_parejas, $puntos);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "id" => $conn->insert_id, "mensaje" => "Categoría añadida correctamente"]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "No se pudo añadir la categoría: " . $conn->error]);
}
?>
