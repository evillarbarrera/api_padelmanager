<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}


error_reporting(0);
ini_set('display_errors', 0);

require_once "../db.php";

$jugador_id = $_GET['jugador_id'] ?? 0;
$entrenador_id = $_GET['entrenador_id'] ?? 0;

if (!$jugador_id) {
    http_response_code(400);
    echo json_encode(["error" => "ID de jugador no proporcionado"]);
    exit;
}

// Get attendance records, optionally filtered by coach
$sql = "SELECT h.*, r.fecha, r.hora_inicio as hora, 
               cm.titulo as titulo_clase, cm.orden, cm.calentamiento, cm.parte_tecnica, cm.drills, cm.juego,
               m.nombre as malla_nombre
        FROM alumno_asistencia h
        LEFT JOIN reservas r ON h.reserva_id = r.id
        LEFT JOIN clases_malla cm ON h.clase_malla_id = cm.id
        LEFT JOIN mallas m ON cm.malla_id = m.id
        WHERE h.jugador_id = ?";

if ($entrenador_id) {
    $sql .= " AND h.entrenador_id = ?";
}

$sql .= " ORDER BY h.id DESC, r.fecha DESC";

$stmt = $conn->prepare($sql);

if ($entrenador_id) {
    $stmt->bind_param("ii", $jugador_id, $entrenador_id);
} else {
    $stmt->bind_param("i", $jugador_id);
}
$stmt->execute();
$res = $stmt->get_result();

$history = [];
while ($row = $res->fetch_assoc()) {
    $row['lista_objetivos'] = json_decode($row['objetivos_logrados'], true) ?: [];
    // If it's a very old record it might not have the json structure, handle it
    $history[] = $row;
}

echo json_encode($history);
?>
