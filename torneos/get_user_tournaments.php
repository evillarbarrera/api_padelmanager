<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

require_once '../db.php';

$user_id = $_GET['user_id'] ?? 0;

if (!$user_id) {
    echo json_encode(['error' => 'Usuario no proporcionado']);
    exit;
}

$sql = "SELECT t.*, c.nombre as club_nombre, c.direccion as club_direccion,
               tp.nombre_pareja, tp.id as inscripcion_id
        FROM torneo_participantes tp
        JOIN torneos_americanos t ON tp.torneo_id = t.id
        JOIN clubes c ON t.club_id = c.id
        WHERE (tp.jugador_id = ? OR tp.jugador2_id = ?)
        AND t.fecha >= CURDATE()
        AND (t.estado != 'Cerrado' OR t.estado IS NULL OR t.estado = '')
        ORDER BY t.fecha ASC, t.hora_inicio ASC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => $conn->error]);
    exit;
}

$stmt->bind_param('ii', $user_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

$torneos = [];
while ($row = $result->fetch_assoc()) {
    $torneos[] = $row;
}

echo json_encode($torneos);
?>
