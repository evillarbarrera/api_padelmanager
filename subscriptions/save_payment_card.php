<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");
error_reporting(E_ALL);
ini_set('display_errors', 0);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";
require_once "subscription_helper.php";

try {
    ensureSubscriptionTablesAndColumns($conn);

    $input = json_decode(file_get_contents("php://input"), true) ?? [];
    $user_id = intval($input['coach_id'] ?? ($input['user_id'] ?? 0));
    $card_holder = trim($input['card_holder'] ?? ($input['holder_name'] ?? ''));
    $card_raw_num = preg_replace('/\D/', '', strval($input['card_number'] ?? ($input['num'] ?? '')));
    $card_expiry = trim($input['card_expiry'] ?? ($input['expiry'] ?? ''));
    $card_brand_input = trim($input['card_brand'] ?? '');

    if (!$user_id) {
        echo json_encode(["success" => false, "message" => "ID de usuario requerido"]);
        exit;
    }

    if (empty($card_holder)) {
        echo json_encode(["success" => false, "message" => "El nombre del titular de la tarjeta es requerido"]);
        exit;
    }

    if (strlen($card_raw_num) < 13 || strlen($card_raw_num) > 19) {
        echo json_encode(["success" => false, "message" => "El número de tarjeta debe tener entre 13 y 19 dígitos"]);
        exit;
    }

    $card_last_four = substr($card_raw_num, -4);

    // Detección automática de marca
    $brand = $card_brand_input;
    if (empty($brand)) {
        if (strpos($card_raw_num, '4') === 0) {
            $brand = 'Visa';
        } elseif (preg_match('/^(5[1-5]|222[1-9]|22[3-9]|2[3-6]|27[01]|2720)/', $card_raw_num)) {
            $brand = 'Mastercard';
        } elseif (preg_match('/^3[47]/', $card_raw_num)) {
            $brand = 'American Express';
        } else {
            $brand = 'Redcompra / Visa';
        }
    }

    $stmt = $conn->prepare("
        UPDATE usuarios 
        SET card_last_four = ?, card_brand = ?, card_holder = ?, card_expiry = ? 
        WHERE id = ?
    ");
    $stmt->bind_param("ssssi", $card_last_four, $brand, $card_holder, $card_expiry, $user_id);
    $stmt->execute();

    echo json_encode([
        "success" => true,
        "message" => "Tarjeta finalizada en **** $card_last_four registrada exitosamente para cobro de comisiones y facturación interna.",
        "card_last_four" => $card_last_four,
        "card_brand" => $brand,
        "card_holder" => $card_holder,
        "card_expiry" => $card_expiry
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
