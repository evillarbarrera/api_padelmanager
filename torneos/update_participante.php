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
$id = $data['id'] ?? 0;
$torneo_id = $data['torneo_id'] ?? 0;
$p1_id = $data['jugador1_id'] ?? null;
$p2_id = $data['jugador2_id'] ?? null;
$n1 = $data['nombre_1'] ?? null;
$n2 = $data['nombre_2'] ?? null;
$nombre_pareja = $data['nombre_pareja'] ?? '';

if (!$id || !$torneo_id) {
    http_response_code(400);
    echo json_encode(["error" => "ID y Torneo ID requeridos"]);
    exit;
}

// 1. Verificar si el torneo está cerrado
$sqlCheck = "SELECT estado FROM torneos_americanos WHERE id = ?";
$stmtC = $conn->prepare($sqlCheck);
$stmtC->bind_param("i", $torneo_id);
$stmtC->execute();
$torneo = $stmtC->get_result()->fetch_assoc();

if (($torneo['estado'] ?? 'Abierto') === 'Cerrado') {
    http_response_code(400);
    echo json_encode(["error" => "El torneo está cerrado"]);
    exit;
}

// 2. Actualizar (Sin validación de duplicados estricta para simplificar la edición de la misma pareja)
$sql = "UPDATE torneo_participantes 
        SET jugador_id = ?, jugador2_id = ?, nombre_pareja = ?, nombre_externo_1 = ?, nombre_externo_2 = ? 
        WHERE id = ? AND torneo_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iisssii", $p1_id, $p2_id, $nombre_pareja, $n1, $n2, $id, $torneo_id);

if ($stmt->execute()) {
    // Si se edita, se deben borrar los partidos previos ya que el fixture ya no es válido
    $conn->query("DELETE FROM torneo_partidos WHERE torneo_id = $torneo_id");
    echo json_encode(["success" => true, "mensaje" => "Pareja actualizada. Los partidos han sido borrados por seguridad."]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al actualizar: " . $conn->error]);
}
?>
