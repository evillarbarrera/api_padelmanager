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

// Get all trainers with their bank and config data
$sql = "SELECT id, nombre, usuario, foto, foto_perfil, telefono, categoria, 
        banco_titular, banco_rut, banco_nombre, banco_tipo_cuenta, banco_numero_cuenta,
        transbank_active, comision_activa, comision_porcentaje
        FROM usuarios WHERE rol = 'entrenador' ORDER BY nombre ASC";

$result = $conn->query($sql);
$entrenadores = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $entrenadores[] = $row;
    }
}

echo json_encode(["success" => true, "data" => $entrenadores]);
