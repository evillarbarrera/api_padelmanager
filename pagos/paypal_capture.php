<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";
require_once "payment_processor.php";

// PAYPAL CONFIG (Replace with your actual keys)
$paypal_client_id = "YOUR_CLIENT_ID";
$paypal_secret = "YOUR_SECRET";
$paypal_env = "sandbox"; // or "live"

$input = json_decode(file_get_contents("php://input"), true);
$orderID = $input['orderID'] ?? null;
$pack_id = $input['pack_id'] ?? null;
$jugador_id = $input['jugador_id'] ?? null;
$amount_usd = $input['amount_usd'] ?? null;

if (!$orderID || !$pack_id || !$jugador_id) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Missing data"]);
    exit;
}

// 1. Get PayPal Access Token
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api-m.sandbox.paypal.com/v1/oauth2/token");
curl_setopt($ch, CURLOPT_HEADER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, $paypal_client_id . ":" . $paypal_secret);
curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=client_credentials");
$response = curl_exec($ch);
$data = json_decode($response);
$access_token = $data->access_token ?? null;

if (!$access_token) {
    echo json_encode(["success" => false, "error" => "Failed to get token"]);
    exit;
}

// 2. Capture the Order
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api-m.sandbox.paypal.com/v2/checkout/orders/$orderID/capture");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer $access_token"
]);
$response = curl_exec($ch);
$capture = json_decode($response);

if ($capture->status === "COMPLETED") {
    // 3. Get Trainer Commission Settings
    $sqlComm = "
        SELECT e.comision_activa, e.comision_porcentaje, e.created_at as coach_created_at
        FROM packs p 
        JOIN usuarios e ON e.id = p.entrenador_id 
        WHERE p.id = ?
    ";
    $stmtComm = $conn->prepare($sqlComm);
    $stmtComm->bind_param("i", $pack_id);
    $stmtComm->execute();
    $commData = $stmtComm->get_result()->fetch_assoc();

    $marketplaceFee = 0;
    if ($commData && $commData['comision_activa'] == 1) {
        // Promo logic check (similar to init_transaction.php)
        $is_promo = false;
        if (!empty($commData['coach_created_at'])) {
            $created = new DateTime($commData['coach_created_at']);
            $now = new DateTime();
            $interval = $created->diff($now);
            if (($interval->y * 12 + $interval->m) < 3) $is_promo = true;
        }

        if (!$is_promo) {
            $marketplaceFee = (float)$amount_usd * ($commData['comision_porcentaje'] / 100);
        }
    }

    // 4. Fulfill Payment in DB
    $fulfillData = [
        "pack_id" => (int)$pack_id,
        "jugador_id" => (int)$jugador_id,
        "amount" => $amount_usd,
        "moneda" => "USD",
        "metodo_pago" => "PayPal",
        "paypal_order_id" => $orderID,
        "comision_plataforma" => $marketplaceFee
    ];

    if (fulfillPayment($conn, $fulfillData)) {
        echo json_encode(["success" => true, "message" => "Payment processed successfully"]);
    } else {
        echo json_encode(["success" => false, "error" => "Internal database error"]);
    }
} else {
    echo json_encode(["success" => false, "error" => "Payment not completed: " . ($capture->status ?? 'Unknown')]);
}
?>
