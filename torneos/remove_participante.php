<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$headers = getallheaders();
require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$participante_id = $data['participante_id'] ?? 0;
$torneo_id = $data['torneo_id'] ?? 0;

if (!$participante_id || !$torneo_id) {
    http_response_code(400);
    echo json_encode(["error" => "Faltan datos requeridos (participante_id, torneo_id)"]);
    exit;
}

// 1. Verificar si el torneo está cerrado
$sqlCheck = "SELECT estado FROM torneos_americanos WHERE id = ?";
$stmtC = $conn->prepare($sqlCheck);
$stmtC->bind_param("i", $torneo_id);
$stmtC->execute();
$torneo = $stmtC->get_result()->fetch_assoc();

if (!$torneo) {
    http_response_code(404);
    echo json_encode(["error" => "Torneo no encontrado"]);
    exit;
}

if (($torneo['estado'] ?? 'Abierto') === 'Cerrado') {
    http_response_code(400);
    echo json_encode(["error" => "El torneo está cerrado y no se pueden quitar participantes"]);
    exit;
}

// 2. Eliminar participante
$sqlDel = "DELETE FROM torneo_participantes WHERE id = ? AND torneo_id = ?";
$stmtD = $conn->prepare($sqlDel);
$stmtD->bind_param("ii", $participante_id, $torneo_id);

if ($stmtD->execute()) {
    if ($stmtD->affected_rows > 0) {
        // Opcional: Si había partidos generados, avisar que deben regenerarse
        // O simplemente borrarlos. Dado que el fixture cambia totalmente si falta una pareja,
        // lo más limpio es borrar los partidos si existen para forzar regeneración.
        $sqlClearMatches = "DELETE FROM torneo_partidos WHERE torneo_id = ?";
        $stmtM = $conn->prepare($sqlClearMatches);
        $stmtM->bind_param("i", $torneo_id);
        $stmtM->execute();

        echo json_encode(["success" => true, "mensaje" => "Participante eliminado. Los partidos han sido borrados y deben regenerarse."]);
    } else {
        http_response_code(404);
        echo json_encode(["error" => "No se encontró el participante o no pertenece a este torneo"]);
    }
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al eliminar: " . $conn->error]);
}
?>
