<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
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

$sql = "SELECT r.*, 
               c.nombre as cancha_nombre, 
               cl.nombre as club_nombre,
               cl.logo as club_logo, 
               cl.direccion as club_direccion,
               u1.nombre as jugador1_nombre,
               u2.nombre as jugador2_nombre,
               u3.nombre as jugador3_nombre,
               u4.nombre as jugador4_nombre
        FROM reservas_cancha r
        JOIN canchas c ON r.cancha_id = c.id
        JOIN clubes cl ON c.club_id = cl.id
        LEFT JOIN usuarios u1 ON r.usuario_id = u1.id
        LEFT JOIN usuarios u2 ON r.jugador2_id = u2.id
        LEFT JOIN usuarios u3 ON r.jugador3_id = u3.id
        LEFT JOIN usuarios u4 ON r.jugador4_id = u4.id
        WHERE r.usuario_id = ? OR r.jugador2_id = ? OR r.jugador3_id = ? OR r.jugador4_id = ?
        ORDER BY r.fecha DESC, r.hora_inicio DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iiii", $userId, $userId, $userId, $userId);
$stmt->execute();
$result = $stmt->get_result();

$partidos = [];
while ($row = $result->fetch_assoc()) {
    // Determinar si ya pasó (partido jugado) o es futuro
    $ahora = new DateTime();
    $fecha_partido = new DateTime($row['fecha'] . ' ' . $row['hora_fin']);
    $row['jugado'] = ($fecha_partido < $ahora);
    
    $partidos[] = $row;
}

echo json_encode($partidos);
