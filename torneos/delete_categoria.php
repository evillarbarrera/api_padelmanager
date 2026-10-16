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

if (!$id) {
    http_response_code(400);
    echo json_encode(["error" => "ID de categoría no proporcionado"]);
    exit;
}

// Inpadel: check if there are inscriptions or groups/matches.
// Usually, we shouldn't allow deleting a category if it has matches or groups already generated.
// For now, let's just delete the category. Database should handle foreign key restrictions if set.

$sql = "DELETE FROM torneo_categorias WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "mensaje" => "Categoría eliminada correctamente"]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "No se pudo eliminar la categoría. Asegúrese de que no tenga inscripciones o partidos asociados: " . $conn->error]);
}
?>
