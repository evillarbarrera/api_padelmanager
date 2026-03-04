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
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$expectedToken = 'Bearer ' . base64_encode("1|padel_academy");

if (empty($auth) || trim($auth) !== trim($expectedToken)) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$entrenador_id = $data['entrenador_id'] ?? 0;

if (!$entrenador_id) {
    http_response_code(400);
    echo json_encode(["error" => "entrenador_id is required"]);
    exit;
}

$titular = $data['banco_titular'] ?? null;
$rut = $data['banco_rut'] ?? null;
$banco = $data['banco_nombre'] ?? null;
$tipo = $data['banco_tipo_cuenta'] ?? null;
$numero = $data['banco_numero_cuenta'] ?? null;

$sql = "UPDATE usuarios SET banco_titular = ?, banco_rut = ?, banco_nombre = ?, banco_tipo_cuenta = ?, banco_numero_cuenta = ? WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sssssi", $titular, $rut, $banco, $tipo, $numero, $entrenador_id);

if ($stmt->execute()) {
    echo json_encode(["success" => true]);
} else {
    echo json_encode(["success" => false, "error" => $conn->error]);
}
