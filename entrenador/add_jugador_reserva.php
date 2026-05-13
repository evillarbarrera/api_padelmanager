<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../auth/auth_helper.php";
$entrenador_id_auth = validateToken();
if (!$entrenador_id_auth) {
    sendUnauthorized();
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    http_response_code(400);
    echo json_encode(["error" => "Datos inválidos"]);
    exit;
}

$reserva_id = $data['reserva_id'] ?? 0;
$jugador_id = $data['jugador_id'] ?? 0;
$pack_id = $data['pack_id'] ?? 0;

if (!$reserva_id || !$jugador_id) {
    http_response_code(400);
    echo json_encode(["error" => "reserva_id y jugador_id son requeridos"]);
    exit;
}

// 1. Verificar que la reserva existe y pertenece al entrenador
$sql_check = "SELECT id, pack_id FROM reservas WHERE id = ? AND entrenador_id = ?";
$stmt = $conn->prepare($sql_check);
$stmt->bind_param("ii", $reserva_id, $entrenador_id_auth);
$stmt->execute();
$reserva = $stmt->get_result()->fetch_assoc();

if (!$reserva) {
    http_response_code(403);
    echo json_encode(["error" => "No tienes permiso para modificar esta reserva"]);
    exit;
}

// 2. Verificar si el jugador ya está en esa sesión
$sql_check_rj = "SELECT id FROM reserva_jugadores WHERE reserva_id = ? AND jugador_id = ?";
$stmt = $conn->prepare($sql_check_rj);
$stmt->bind_param("ii", $reserva_id, $jugador_id);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) {
    http_response_code(400);
    echo json_encode(["error" => "El jugador ya está en esta clase"]);
    exit;
}

// 3. Insertar en reserva_jugadores
$sql_insert = "INSERT INTO reserva_jugadores (reserva_id, jugador_id) VALUES (?, ?)";
$stmt = $conn->prepare($sql_insert);
$stmt->bind_param("ii", $reserva_id, $jugador_id);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Jugador agregado a la sesión"]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al agregar: " . $conn->error]);
}
?>
