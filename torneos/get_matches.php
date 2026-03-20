<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
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

$torneo_id = $_GET['torneo_id'] ?? 0;

if (!$torneo_id) {
    http_response_code(400);
    echo json_encode(["error" => "torneo_id es requerido"]);
    exit;
}

$sql = "SELECT tp.*, 
               COALESCE(u1.nombre, tp.nombre_externo_1, 'J1') as jugador1_nombre, 
               COALESCE(u2.nombre, tp.nombre_externo_2, 'J2') as jugador2_nombre, 
               COALESCE(u3.nombre, tp.nombre_externo_3, 'J3') as jugador3_nombre, 
               COALESCE(u4.nombre, tp.nombre_externo_4, 'J4') as jugador4_nombre
        FROM torneo_partidos tp
        LEFT JOIN usuarios u1 ON tp.jugador1_id = u1.id
        LEFT JOIN usuarios u2 ON tp.jugador2_id = u2.id
        LEFT JOIN usuarios u3 ON tp.jugador3_id = u3.id
        LEFT JOIN usuarios u4 ON tp.jugador4_id = u4.id
        WHERE tp.torneo_id = ?
        ORDER BY tp.ronda ASC, tp.id ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $torneo_id);
$stmt->execute();
$result = $stmt->get_result();

$partidos = [];
while ($row = $result->fetch_assoc()) {
    $partidos[] = $row;
}

echo json_encode($partidos);
?>
