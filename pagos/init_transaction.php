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

require_once "mercado_pago_service.php";

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

// 1. Get Pack and Trainer Details
$sqlPack = "
    SELECT p.*, e.id as entrenador_id, e.comision_activa, e.comision_porcentaje, e.mp_collector_id, e.created_at as coach_created_at
    FROM packs p 
    JOIN usuarios e ON e.id = p.entrenador_id 
    WHERE p.id = ?
";
$stmtPack = $conn->prepare($sqlPack);
$stmtPack->bind_param("i", $pack_id);
$stmtPack->execute();
$pack = $stmtPack->get_result()->fetch_assoc();

if (!$pack) {
    http_response_code(404);
    echo json_encode(["error" => "Pack no encontrado"]);
    exit;
}

// 2. Capacidad check
if ($pack['tipo'] === 'grupal' && $pack['cupos_ocupados'] >= $pack['capacidad_maxima']) {
    http_response_code(400);
    echo json_encode(["error" => "Lo sentimos, no quedan cupos disponibles."]);
    exit;
}

// 3. Calculate Commission (Marketplace Fee)
$finalAmount = (float)($amount ?: $pack['precio']);
$marketplaceFee = 0;

// Promo logic: 3 months free for new coaches
$is_promo_period = false;
if (isset($pack['coach_created_at']) && !empty($pack['coach_created_at'])) {
    $created = new DateTime($pack['coach_created_at']);
    $now = new DateTime();
    $interval = $created->diff($now);
    $totalMonths = ($interval->y * 12) + $interval->m;
    if ($totalMonths < 3) {
        $is_promo_period = true;
    }
}

if ($pack['comision_activa'] == 1 && !$is_promo_period) {
    $marketplaceFee = $finalAmount * ($pack['comision_porcentaje'] / 100);
}

// 4. Initiate Mercado Pago Preference
$prefData = [
    "pack_id" => (int)$pack_id,
    "jugador_id" => (int)$jugador_id,
    "amount" => $finalAmount,
    "marketplace_fee" => $marketplaceFee,
    "trainer_mp_id" => $pack['mp_collector_id'],
    "title" => $pack['nombre'],
    "origin" => $origin,
    "reserva_id" => $reserva_id
];



$preference = MercadoPagoService::createPreference($prefData);

if ($preference && isset($preference['init_point'])) {
    echo json_encode([
        "success" => true,
        "token" => $preference['id'], // We send the preference ID as token
        "url" => $preference['init_point'], // Mercado Pago Checkout URL
        "message" => "Transacción iniciada"
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        "success" => false, 
        "error" => "Error al crear preferencia de Mercado Pago"
    ]);
}

