<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$headers = getallheaders();
$auth = $headers['Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

if ($auth !== 'Bearer ' . base64_encode("1|padel_academy")) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$pack_id = $_GET['pack_id'] ?? 0;

if (!$pack_id) {
    http_response_code(400);
    echo json_encode(["error" => "pack_id es requerido"]);
    exit;
}

require_once "../db.php";

// Obtener todas las inscripciones activas del pack
$sql = "SELECT ig.*, u.nombre, u.email 
        FROM inscripciones_grupales ig
        JOIN usuarios u ON u.id = ig.jugador_id
        WHERE ig.pack_id = ? AND ig.estado = 'activo'
        ORDER BY ig.fecha_inscripcion ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $pack_id);
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode($data);
