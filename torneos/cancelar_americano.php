<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$torneo_id = $data['torneo_id'] ?? 0;

if (!$torneo_id) {
    http_response_code(400);
    echo json_encode(["error" => "ID de torneo no proporcionado"]);
    exit;
}

$sql = "UPDATE torneos_americanos SET estado = 'Cerrado' WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $torneo_id);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Torneo cancelado/cerrado correctamente"]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al actualizar estado: " . $conn->error]);
}
?>
