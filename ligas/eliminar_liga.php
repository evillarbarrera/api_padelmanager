<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
require_once "../db.php";

$input = json_decode(file_get_contents("php://input"), true);
$id = $input['id'] ?? ($_GET['id'] ?? null);

if (!$id) {
    echo json_encode(["success" => false, "error" => "ID de liga requerido"]);
    exit;
}

try {
    $stmt = $conn->prepare("DELETE FROM ligas WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        echo json_encode(["success" => true, "mensaje" => "Liga eliminada exitosamente"]);
    } else {
        echo json_encode(["success" => false, "error" => "No se encontró la liga o ya fue eliminada"]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
