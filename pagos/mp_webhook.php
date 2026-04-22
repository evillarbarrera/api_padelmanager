<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once "../db.php";
require_once "mercado_pago_service.php";
require_once "payment_processor.php";

$data = json_decode(file_get_contents("php://input"), true);

if (isset($data['type']) && $data['type'] === 'payment') {
    $paymentId = $data['data']['id'] ?? null;
    
    if ($paymentId) {
        $paymentInfo = MercadoPagoService::getPaymentStatus($paymentId);
        
        if ($paymentInfo && $paymentInfo['status'] === 'approved') {
            $externalReference = json_decode($paymentInfo['external_reference'], true);
            if ($externalReference) {
                // Enrich reference with actual payment data from MP
                $externalReference['amount'] = $paymentInfo['transaction_amount'] ?? 0;
                $externalReference['metodo_pago'] = $paymentInfo['payment_method_id'] ?? 'Mercado Pago';
                $externalReference['moneda'] = $paymentInfo['currency_id'] ?? 'CLP';

                fulfillPayment($conn, $externalReference);
            }
        }
    }
}

// Always respond with 200 to Mercado Pago
http_response_code(200);
echo json_encode(["status" => "ok"]);
