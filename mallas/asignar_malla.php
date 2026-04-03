<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
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


require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$jugador_id = $data['jugador_id'] ?? 0;
$malla_id = $data['malla_id'] ?? 0;
$entrenador_id = $data['entrenador_id'] ?? 0;

if (!$jugador_id || !$malla_id || !$entrenador_id) {
    http_response_code(400);
    echo json_encode(["error" => "Datos incompletos"]);
    exit;
}

// 1. Desactivar mallas previas si hubiera
$conn->query("UPDATE alumno_malla_seguimiento SET estado = 'cancelado' WHERE jugador_id = $jugador_id AND estado = 'activo'");

// 2. Insertar nueva malla
$sql = "INSERT INTO alumno_malla_seguimiento (jugador_id, malla_id, entrenador_id, estado, clase_actual_orden, fecha_inicio) 
        VALUES (?, ?, ?, 'activo', 1, NOW())";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $jugador_id, $malla_id, $entrenador_id);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "id" => $conn->insert_id]);
} else {
    http_response_code(500);
    echo json_encode(["error" => $conn->error]);
}
?>
