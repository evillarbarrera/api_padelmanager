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
$participante_id = $data['participante_id'] ?? 0;
$player_num = $data['player_num'] ?? 0; // 1 or 2
$checked_in = isset($data['checked_in']) ? (int)$data['checked_in'] : 0; // 0 or 1

if (!$participante_id || ($player_num !== 1 && $player_num !== 2)) {
    http_response_code(400);
    echo json_encode(["error" => "Datos inválidos o incompletos"]);
    exit;
}

$column = ($player_num === 1) ? 'check_in_1' : 'check_in_2';

$sql = "UPDATE torneo_participantes SET $column = ? WHERE id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => "Error preparando base de datos: " . $conn->error]);
    exit;
}

$stmt->bind_param("ii", $checked_in, $participante_id);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Estado de check-in actualizado correctamente"]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al actualizar check-in: " . $conn->error]);
}
?>
