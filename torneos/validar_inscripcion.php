<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$inscripcion_id = $data['inscripcion_id'] ?? 0;
$estado = $data['validado'] ?? 1; // 1 para validar, 0 para quitar validación

if (!$inscripcion_id) {
    http_response_code(400);
    echo json_encode(["error" => "ID de inscripción requerido"]);
    exit;
}

$sql = "UPDATE torneo_inscripciones SET validado = ?, pagado = ? WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $estado, $estado, $inscripcion_id);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "mensaje" => "Estado de inscripción actualizado"]);
} else {
    http_response_code(500);
    echo json_encode(["error" => $conn->error]);
}
?>
