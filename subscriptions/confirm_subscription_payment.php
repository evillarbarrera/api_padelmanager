<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");
error_reporting(E_ALL);
ini_set('display_errors', 0);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";
require_once "../pagos/mercado_pago_service.php";
require_once "subscription_helper.php";

try {
    // 1. Obtener parámetros (POST json o GET query params)
    $input = json_decode(file_get_contents("php://input"), true) ?? [];
    
    $user_id = intval($input['user_id'] ?? ($input['coach_id'] ?? ($_GET['user_id'] ?? ($_GET['coach_id'] ?? 0))));
    $plan_id = intval($input['plan_id'] ?? ($_GET['plan_id'] ?? 0));
    $payment_id = $input['payment_id'] ?? ($_GET['payment_id'] ?? ($_GET['collection_id'] ?? null));
    $paypal_sub_id = $input['paypal_sub_id'] ?? ($_GET['paypal_sub_id'] ?? null);
    $status_param = $input['status'] ?? ($_GET['status'] ?? ($_GET['payment_status'] ?? ''));

    if (!$user_id) {
        echo json_encode(["success" => false, "message" => "ID de usuario requerido"]);
        exit;
    }

    $metodo_pago = "Mercado Pago";
    $moneda = "CLP";
    $monto = 0;

    // 2. Si viene payment_id de Mercado Pago, verificar estado con la API
    if ($payment_id) {
        $paymentInfo = MercadoPagoService::getPaymentStatus($payment_id);
        if ($paymentInfo) {
            $mpStatus = $paymentInfo['status'] ?? '';
            if ($mpStatus !== 'approved' && $status_param !== 'approved' && $status_param !== 'success') {
                echo json_encode([
                    "success" => false, 
                    "message" => "El pago no figura como aprobado en Mercado Pago (Estado: $mpStatus)"
                ]);
                exit;
            }
            $monto = (float)($paymentInfo['transaction_amount'] ?? 0);
            $moneda = $paymentInfo['currency_id'] ?? 'CLP';
            $metodo_pago = "Mercado Pago (" . ($paymentInfo['payment_method_id'] ?? 'Online') . ")";
        }
    } elseif ($paypal_sub_id) {
        $metodo_pago = "PayPal";
        $moneda = "USD";
    }

    // 3. Procesar y extender el mes (+1 mes) con idempotencia
    $result = processSubscriptionPayment($conn, [
        "user_id" => $user_id,
        "plan_id" => $plan_id,
        "amount" => $monto,
        "moneda" => $moneda,
        "metodo_pago" => $metodo_pago,
        "payment_id" => $payment_id,
        "preapproval_id" => $paypal_sub_id
    ]);

    if (!empty($result['success'])) {
        echo json_encode($result);
    } else {
        http_response_code(400);
        echo json_encode($result);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
