<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

// Responder OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Obtener datos del body
$data = json_decode(file_get_contents("php://input"), true);
file_put_contents("debug_editar.log", print_r($data, true)); // DEBUG

echo json_encode(["status" => "received", "data" => $data]);
?>
