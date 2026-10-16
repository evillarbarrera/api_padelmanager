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
$id = $data['id'] ?? 0;
$es_semilla = isset($data['es_semilla']) ? (int)$data['es_semilla'] : 0;
$nro_siembra = isset($data['nro_siembra']) ? (int)$data['nro_siembra'] : null;

if (!$id) {
    http_response_code(400);
    echo json_encode(["error" => "ID de inscripción requerido"]);
    exit;
}

$sql = "UPDATE torneo_inscripciones SET es_semilla = ?, nro_siembra = ? WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $es_semilla, $nro_siembra, $id);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "mensaje" => "Siembra actualizada correctamente"]);
} else {
    http_response_code(500);
    echo json_encode(["error" => $conn->error]);
}
?>
