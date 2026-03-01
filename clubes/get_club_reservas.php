<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

$headers = getallheaders();
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';

// Simple Auth Check
if (empty($auth)) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$club_id = $_GET['club_id'] ?? 0;
$fecha = $_GET['fecha'] ?? date('Y-m-d');

if (!$club_id) {
    echo json_encode([]);
    exit;
}

// Get all reservations for all courts of this club on a specific date
$sql = "SELECT r.*, c.nombre as cancha_nombre, u.nombre as jugador_nombre 
        FROM reservas_cancha r 
        JOIN canchas c ON r.cancha_id = c.id 
        LEFT JOIN usuarios u ON r.usuario_id = u.id 
        WHERE c.club_id = ? AND r.fecha = ?
        ORDER BY r.hora_inicio ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $club_id, $fecha);
$stmt->execute();
$result = $stmt->get_result();

$reservas = [];
while ($row = $result->fetch_assoc()) {
    $reservas[] = $row;
}

echo json_encode($reservas);
