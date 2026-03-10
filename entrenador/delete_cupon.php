<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

$headers = getallheaders();
$auth = $headers['Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if ($auth !== 'Bearer ' . base64_encode("1|padel_academy")) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$id = $data['id'] ?? null;
$entrenador_id = $data['entrenador_id'] ?? null;

if (!$id || !$entrenador_id) {
    echo json_encode(["error" => "ID y entrenador_id requeridos"]);
    exit;
}

// Soft delete
$sql = "UPDATE cupones SET activo = 0 WHERE id = ? AND entrenador_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $id, $entrenador_id);

if ($stmt->execute()) {
    echo json_encode(["success" => true]);
} else {
    echo json_encode(["error" => "Error al eliminar el cupón"]);
}

$conn->close();
?>
