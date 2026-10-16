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
require_once "../auth/auth_helper.php";

$userId = validateToken();
if (!$userId) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

$reserva_id = $data['reserva_id'] ?? 0;
$marcador = $data['marcador'] ?? '';
$categoria = $data['categoria'] ?? '';
$id_ganador = $data['id_ganador'] ?? null; // 1 para dupla 1 (jugador 1 y 2), 2 para dupla 2 (jugador 3 y 4)

// También permitimos actualizar los jugadores si no estaban seteados
$jugador2_id = !empty($data['jugador2_id']) ? intval($data['jugador2_id']) : null;
$jugador3_id = !empty($data['jugador3_id']) ? intval($data['jugador3_id']) : null;
$jugador4_id = !empty($data['jugador4_id']) ? intval($data['jugador4_id']) : null;

if (!$reserva_id) {
    http_response_code(400);
    echo json_encode(["error" => "reserva_id es requerido"]);
    exit;
}

// Verificar que el usuario sea el titular de la reserva (o esté en ella)
$check = "SELECT usuario_id, jugador2_id, jugador3_id, jugador4_id FROM reservas_cancha WHERE id = ?";
$stmtCheck = $conn->prepare($check);
$stmtCheck->bind_param("i", $reserva_id);
$stmtCheck->execute();
$res = $stmtCheck->get_result()->fetch_assoc();

if (!$res) {
    http_response_code(404);
    echo json_encode(["error" => "Reserva no encontrada"]);
    exit;
}

if ($res['usuario_id'] != $userId && $res['jugador2_id'] != $userId && $res['jugador3_id'] != $userId && $res['jugador4_id'] != $userId) {
    http_response_code(403);
    echo json_encode(["error" => "No tienes permiso para editar esta reserva"]);
    exit;
}

$sql = "UPDATE reservas_cancha SET 
        marcador = ?, 
        categoria = ?, 
        id_ganador = ?, 
        resultado_registrado = 1,
        jugador2_id = COALESCE(?, jugador2_id),
        jugador3_id = COALESCE(?, jugador3_id),
        jugador4_id = COALESCE(?, jugador4_id)
        WHERE id = ?";

// Nota: bind_param no acepta null directamente de forma limpia para COALESCE si no se maneja bien,
// pero aquí re-mapeamos para asegurar
$jugador_id2 = $jugador2_id;
$jugador_id3 = $jugador3_id;
$jugador_id4 = $jugador4_id;

$stmt = $conn->prepare($sql);
$stmt->bind_param("ssiiiii", $marcador, $categoria, $id_ganador, $jugador_id2, $jugador_id3, $jugador_id4, $reserva_id);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Resultado guardado correctamente"]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al guardar: " . $conn->error]);
}
