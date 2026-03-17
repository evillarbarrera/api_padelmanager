<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

$jugador_id = $_GET['jugador_id'] ?? 0;
$entrenador_id = $_GET['entrenador_id'] ?? 0;

if (!$jugador_id) {
    http_response_code(400);
    echo json_encode([]);
    exit;
}

$sql = "SELECT e.id, e.fecha, e.promedio_general, e.comentarios, e.scores, e.entrenador_id, u.nombre as entrenador, u.usuario as email_entrenador
        FROM evaluaciones e
        LEFT JOIN usuarios u ON e.entrenador_id = u.id
        WHERE e.jugador_id = ?";

if ($entrenador_id) {
    $sql .= " AND e.entrenador_id = ?";
}

$sql .= " ORDER BY e.fecha DESC";

$stmt = $conn->prepare($sql);
if ($entrenador_id) {
    $stmt->bind_param("ii", $jugador_id, $entrenador_id);
} else {
    $stmt->bind_param("i", $jugador_id);
}
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    // Decodificar JSON para que el frontend lo reciba como objeto
    $row['scores'] = json_decode($row['scores']); 
    $data[] = $row;
}

echo json_encode($data);
