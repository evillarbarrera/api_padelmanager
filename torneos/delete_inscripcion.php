<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$id = $data['inscripcion_id'] ?? 0;

if (!$id) {
    echo json_encode(["error" => "ID de inscripción requerido"]);
    exit;
}

// Verificar que existe la tabla correcta, asumimos v2
$sql = "DELETE FROM torneo_inscripciones WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "mensaje" => "Inscripción eliminada"]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al eliminar inscripción: " . $conn->error]);
}
?>
