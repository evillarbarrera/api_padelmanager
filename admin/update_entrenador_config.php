<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$headers = getallheaders();
require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}

$data = json_decode(file_get_contents("php://input"), true);
$entrenador_id = $data['entrenador_id'] ?? 0;

if (!$entrenador_id) {
    http_response_code(400);
    echo json_encode(["error" => "entrenador_id is required"]);
    exit;
}

$transbank = isset($data['transbank_activo']) ? intval($data['transbank_activo']) : 1;
$comision = isset($data['comision_activa']) ? intval($data['comision_activa']) : 1;

$porcentaje = isset($data['comision_porcentaje']) ? floatval($data['comision_porcentaje']) : 3.50;
$mp_id = $data['mp_collector_id'] ?? null;

$sql = "UPDATE usuarios SET transbank_activo = ?, comision_activa = ?, comision_porcentaje = ?, mp_collector_id = ? WHERE id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iidsi", $transbank, $comision, $porcentaje, $mp_id, $entrenador_id);


if ($stmt->execute()) {
    echo json_encode(["success" => true]);
} else {
    echo json_encode(["success" => false, "error" => $conn->error]);
}
