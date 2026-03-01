<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["error" => "JSON inválido"]);
    exit;
}

$entrenador_id = $data['entrenador_id'] ?? null;
$fecha = $data['fecha'] ?? null;
$hora_inicio = $data['hora_inicio'] ?? null;
$hora_fin = $data['hora_fin'] ?? null;
$jugador_id = $data['jugador_id'] ?? null;

if (!$entrenador_id || !$fecha || !$hora_inicio || !$hora_fin || !$jugador_id) {
    http_response_code(400);
    echo json_encode(["error" => "Faltan campos obligatorios"]);
    exit;
}

try {
    // 1. Validar si el horario ya está ocupado (incluyendo bloqueos recientes de 10 min)
    $stmtOccupied = $conn->prepare("
        SELECT id FROM reservas 
        WHERE entrenador_id = ? 
        AND fecha = ? 
        AND hora_inicio < ? 
        AND hora_fin > ? 
        AND (
            estado = 'reservado' 
            OR (estado = 'bloqueado' AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE))
        )
    ");
    $stmtOccupied->bind_param("isss", $entrenador_id, $fecha, $hora_fin, $hora_inicio);
    $stmtOccupied->execute();
    if ($stmtOccupied->get_result()->num_rows > 0) {
        http_response_code(400);
        echo json_encode(["error" => "El horario ya no está disponible o está siendo reservado por otra persona."]);
        exit;
    }

    $conn->begin_transaction();

    // 2. Insertar reserva con estado 'bloqueado'
    $stmtReserva = $conn->prepare("
        INSERT INTO reservas (entrenador_id, pack_id, fecha, hora_inicio, hora_fin, estado)
        VALUES (?, 0, ?, ?, ?, 'bloqueado')
    ");
    $stmtReserva->bind_param("isss", $entrenador_id, $fecha, $hora_inicio, $hora_fin);
    $stmtReserva->execute();
    $reserva_id = $conn->insert_id;

    // 3. Insertar en reserva_jugadores
    $stmtJugador = $conn->prepare("
        INSERT INTO reserva_jugadores (reserva_id, jugador_id)
        VALUES (?, ?)
    ");
    $stmtJugador->bind_param("ii", $reserva_id, $jugador_id);
    $stmtJugador->execute();

    $conn->commit();

    echo json_encode([
        "ok" => true,
        "reserva_id" => $reserva_id,
        "message" => "Horario bloqueado por 10 minutos."
    ]);

} catch (Exception $e) {
    if ($conn) $conn->rollback();
    http_response_code(500);
    echo json_encode(["error" => "Error al crear la pre-reserva: " . $e->getMessage()]);
}
