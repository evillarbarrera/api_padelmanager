<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);

$id = $data['id'] ?? 0;
$cancha_id = $data['cancha_id'] ?? 0;
$fecha = $data['fecha'] ?? '';
$hora_inicio = $data['hora_inicio'] ?? '';
$hora_fin = $data['hora_fin'] ?? '';

if (!$id || !$cancha_id || !$fecha || !$hora_inicio) {
    http_response_code(400);
    echo json_encode(["error" => "Faltan datos obligatorios"]);
    exit;
}

// 1. Validar disponibilidad (excluyendo la reserva actual)
$sqlCheck = "SELECT id FROM reservas_cancha 
             WHERE cancha_id = ? AND fecha = ? AND id != ?
             AND ((hora_inicio < ? AND hora_fin > ?) OR (hora_inicio < ? AND hora_fin > ?))
             AND estado != 'Cancelada'";
$stmtCheck = $conn->prepare($sqlCheck);
$stmtCheck->bind_param("issssss", $cancha_id, $fecha, $id, $hora_fin, $hora_inicio, $hora_inicio, $hora_inicio);
$stmtCheck->execute();
if ($stmtCheck->get_result()->num_rows > 0) {
    http_response_code(409);
    echo json_encode(["error" => "Choque de horario con otra reserva"]);
    exit;
}

// 2. Actualizar reserva
$sqlUpdate = "UPDATE reservas_cancha SET 
              cancha_id = ?, 
              usuario_id = ?, jugador2_id = ?, jugador3_id = ?, jugador4_id = ?, 
              nombre_externo = ?, nombre_externo2 = ?, nombre_externo3 = ?, nombre_externo4 = ?, 
              fecha = ?, hora_inicio = ?, hora_fin = ?, precio = ?, pagado = ?, estado = ?
              WHERE id = ?";

$stmt = $conn->prepare($sqlUpdate);
$stmt->bind_param("iiiiisssssssdiss", 
    $cancha_id, 
    $data['jugador_id'], $data['jugador2_id'], $data['jugador3_id'], $data['jugador4_id'],
    $data['nombre_externo'], $data['nombre_externo2'], $data['nombre_externo3'], $data['nombre_externo4'],
    $fecha, $hora_inicio, $hora_fin, $data['precio'], $data['pagado'], $data['estado'], $id
);

if ($stmt->execute()) {
    echo json_encode(["success" => true]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al actualizar la reserva: " . $conn->error]);
}
?>
