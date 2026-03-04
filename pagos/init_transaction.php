<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$input = json_decode(file_get_contents("php://input"), true);
$pack_id = $input['pack_id'] ?? 0;
$jugador_id = $input['jugador_id'] ?? 0;
$amount = $input['amount'] ?? 0;
$origin = $input['origin'] ?? 'https://padelmanager.cl/pack-alumno';
$reserva_id = $input['reserva_id'] ?? null;

if (!$pack_id || !$jugador_id) {
    http_response_code(400);
    echo json_encode(["error" => "Datos invalidos"]);
    exit;
}

// 1. Get Pack Details to check availability
$sqlPack = "SELECT id, nombre, precio, tipo, capacidad_maxima, cupos_ocupados FROM packs WHERE id = ?";
$stmtPack = $conn->prepare($sqlPack);
$stmtPack->bind_param("i", $pack_id);
$stmtPack->execute();
$pack = $stmtPack->get_result()->fetch_assoc();

if (!$pack) {
    http_response_code(404);
    echo json_encode(["error" => "Pack no encontrado"]);
    exit;
}

// 2. Capacidad check for group packs
if ($pack['tipo'] === 'grupal' && $pack['cupos_ocupados'] >= $pack['capacidad_maxima']) {
    http_response_code(400);
    echo json_encode(["error" => "Lo sentimos, no quedan cupos disponibles."]);
    exit;
}

// 3. Initiate Mock Transaction
// In a real Transbank integration, we would create a transaction in their API here.
// For this Mock, we generate a token that carries the payload.

$tokenData = [
    "pack_id" => (int)$pack_id,
    "jugador_id" => (int)$jugador_id,
    "amount" => (int)($amount ?: $pack['precio']),
    "origin" => $origin,
    "reserva_id" => $reserva_id,
    "ts" => time()
];

$token = base64_encode(json_encode($tokenData));
$url = "https://api.padelmanager.cl/pagos/mock_bank.php";

echo json_encode([
    "success" => true,
    "token" => $token,
    "url" => $url,
    "message" => "Transacción iniciada"
]);
