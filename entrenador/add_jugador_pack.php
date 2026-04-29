<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../auth/auth_helper.php";
$entrenador_id_auth = validateToken();
if (!$entrenador_id_auth) {
    sendUnauthorized();
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    http_response_code(400);
    echo json_encode(["error" => "Datos inválidos"]);
    exit;
}

$pack_id = $data['pack_id'] ?? 0;
$jugador_id = $data['jugador_id'] ?? 0;

if (!$pack_id || !$jugador_id) {
    http_response_code(400);
    echo json_encode(["error" => "pack_id y jugador_id son requeridos"]);
    exit;
}

// 1. Verificar que el pack pertenece al entrenador y es grupal
$sql_check_pack = "SELECT * FROM packs WHERE id = ? AND entrenador_id = ? AND tipo = 'grupal'";
$stmt = $conn->prepare($sql_check_pack);
$stmt->bind_param("ii", $pack_id, $entrenador_id_auth);
$stmt->execute();
$pack = $stmt->get_result()->fetch_assoc();

if (!$pack) {
    http_response_code(403);
    echo json_encode(["error" => "No tienes permiso para modificar este pack o el pack no existe"]);
    exit;
}

// 2. Verificar si el jugador ya está inscrito
$sql_check_insc = "SELECT id FROM inscripciones_grupales WHERE pack_id = ? AND jugador_id = ? AND estado = 'activo'";
$stmt = $conn->prepare($sql_check_insc);
$stmt->bind_param("ii", $pack_id, $jugador_id);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) {
    http_response_code(400);
    echo json_encode(["error" => "El jugador ya está inscrito en este entrenamiento"]);
    exit;
}

// 3. Insertar inscripción
$sql_insert = "INSERT INTO inscripciones_grupales (pack_id, jugador_id, fecha_inscripcion, estado) VALUES (?, ?, NOW(), 'activo')";
$stmt = $conn->prepare($sql_insert);
$stmt->bind_param("ii", $pack_id, $jugador_id);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Jugador agregado exitosamente"]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al agregar jugador: " . $conn->error]);
}
?>
