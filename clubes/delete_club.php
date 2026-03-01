<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$club_id = $data['id'] ?? 0;
$admin_id = $data['admin_id'] ?? 0;

if (!$club_id || !$admin_id) {
    http_response_code(400);
    echo json_encode(["error" => "ID de club y Admin ID son requeridos"]);
    exit;
}

// 1. Verificar propiedad
$checkProp = $conn->prepare("SELECT id FROM clubes WHERE id = ? AND admin_id = ?");
$checkProp->bind_param("ii", $club_id, $admin_id);
$checkProp->execute();
if ($checkProp->get_result()->num_rows === 0) {
    http_response_code(403);
    echo json_encode(["error" => "No tienes permiso para borrar este club o no existe"]);
    exit;
}

// 2. Verificar asociaciones (Canchas)
$checkCanchas = $conn->prepare("SELECT id FROM canchas WHERE club_id = ?");
$checkCanchas->bind_param("i", $club_id);
$checkCanchas->execute();
if ($checkCanchas->get_result()->num_rows > 0) {
    http_response_code(400);
    echo json_encode(["error" => "No se puede borrar el club porque tiene canchas asociadas. Borra primero las canchas."]);
    exit;
}

// 3. Verificar asociaciones (Torneos Americanos)
$checkTorneosA = $conn->prepare("SELECT id FROM torneos_americanos WHERE club_id = ?");
$checkTorneosA->bind_param("i", $club_id);
$checkTorneosA->execute();
if ($checkTorneosA->get_result()->num_rows > 0) {
    http_response_code(400);
    echo json_encode(["error" => "No se puede borrar el club porque tiene torneos americanos asociados."]);
    exit;
}

// 4. Verificar asociaciones (Torneos V2)
$checkTorneosV2 = $conn->prepare("SELECT id FROM torneos_v2 WHERE club_id = ?");
$checkTorneosV2->bind_param("i", $club_id);
$checkTorneosV2->execute();
if ($checkTorneosV2->get_result()->num_rows > 0) {
    http_response_code(400);
    echo json_encode(["error" => "No se puede borrar el club porque tiene torneos PRO asociados."]);
    exit;
}

// 5. Proceder a borrar
$delete = $conn->prepare("DELETE FROM clubes WHERE id = ?");
$delete->bind_param("i", $club_id);

if ($delete->execute()) {
    echo json_encode(["success" => true, "message" => "Club eliminado correctamente"]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al eliminar el club: " . $conn->error]);
}
?>
