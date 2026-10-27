<?php
require_once "mp_config.php";

class MercadoPagoService {
    
    public static function createPreference($data) {
        $url = MP_API_URL . "/checkout/preferences";
        
        $preference = [
            "items" => [
                [
                    "id" => $data['pack_id'],
                    "title" => $data['title'],
                    "quantity" => 1,
                    "currency_id" => "CLP",
                    "unit_price" => (float)$data['amount']
                ]
            ],
            "back_urls" => [
                "success" => $data['origin'] . "?status=success" . (!empty($data['reserva_id']) ? "&reserva=confirmed" : ""),
                "failure" => $data['origin'] . "?status=error",
                "pending" => $data['origin'] . "?status=pending"
            ],

            "auto_return" => "approved",
            "external_reference" => json_encode([
                "pack_id" => $data['pack_id'],
                "jugador_id" => $data['jugador_id'],
                "reserva_id" => $data['reserva_id'] ?? null,
                "cupon_id" => $data['cupon_id'] ?? null
            ]),
            "notification_url" => "https://api.padelmanager.cl/pagos/mp_webhook.php",
            "statement_descriptor" => "PADEL MANAGER",
            "binary_mode" => true
        ];

        // Split Payment (Marketplace)
        if (!empty($data['trainer_mp_id'])) {
            $preference['collector_id'] = (int)$data['trainer_mp_id'];
            $preference['marketplace_fee'] = (float)$data['marketplace_fee'];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . MP_ACCESS_TOKEN,
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($preference));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        } else {
            error_log("Mercado Pago Preference Error: " . $response);
            return null;
        }
    }

    public static function getPaymentStatus($paymentId) {
        $url = MP_API_URL . "/v1/payments/" . $paymentId;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . MP_ACCESS_TOKEN
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return json_decode($response, true);
        }
        return null;
    }

    /**
     * Generates the OAuth URL for secondary users (trainers)
     */
    public static function getAuthUrl($userId) {
        return "https://auth.mercadopago.cl/authorization?client_id=" . MP_CLIENT_ID . 
               "&response_type=code&platform_id=mp&redirect_uri=" . urlencode(MP_REDIRECT_URI) . 
               "&state=" . $userId;
    }

    /**
     * Exchanges auth code for access token and collector_id
     */
    public static function getAccessToken($code) {
        $url = MP_API_URL . "/oauth/token";
        
        $payload = [
            "client_id" => MP_CLIENT_ID,
            "client_secret" => MP_CLIENT_SECRET,
            "grant_type" => "authorization_code",
            "code" => $code,
            "redirect_uri" => MP_REDIRECT_URI
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        } else {
            error_log("Mercado Pago OAuth Error: " . $response);
            return null;
        }
    }

    /**
     * Creates a recurring subscription (Pre-approval)
     */
    public static function createPreApproval($data) {
        $url = MP_API_URL . "/preapproval";
        
        $preapproval = [
            "payer_email" => $data['payer_email'],
            "back_url" => $data['origin'] . "?status=success_sub",
            "reason" => "Membresía Padel Manager - " . $data['plan_name'],
            "auto_recurring" => [
                "frequency" => 1,
                "frequency_type" => "months",
                "transaction_amount" => (float)$data['amount'],
                "currency_id" => "CLP"
            ],
            "external_reference" => json_encode([
                "coach_id" => $data['coach_id'],
                "plan_id" => $data['plan_id']
            ]),
            "status" => "pending"
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . MP_ACCESS_TOKEN,
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($preapproval));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return $decoded;
        } else {
            error_log("Mercado Pago Pre-approval Error: " . $response);
            return [
                "error" => true,
                "status" => $httpCode,
                "message" => $decoded['message'] ?? "Error desconocido de Mercado Pago (Code: $httpCode)",
                "raw" => $response
            ];
        }
    }

    /**
     * Creates a Checkout Pro preference for subscription renewal (+1 month)
     * Compatible with Webpay, Redcompra, Debit, Credit and MP wallet in Chile
     */
    public static function createSubscriptionRenewalPreference($data) {
        $url = MP_API_URL . "/checkout/preferences";
        
        $userId = (int)($data['user_id'] ?? ($data['coach_id'] ?? 0));
        $planId = (int)($data['plan_id'] ?? 0);
        $planName = $data['plan_name'] ?? 'Membresía Mensual Padelblox';
        $amount = (float)($data['amount'] ?? ($data['price'] ?? 0));
        $rawOrigin = rtrim($data['origin'] ?? 'https://padelblox.cl/mi-plan', '/');
        // Mercado Pago exige HTTPS y no acepta localhost cuando auto_return está activo
        if (empty($rawOrigin) || strpos($rawOrigin, 'localhost') !== false || strpos($rawOrigin, '127.0.0.1') !== false || strpos($rawOrigin, 'https://') !== 0) {
            $origin = "https://padelblox.cl/mi-plan";
        } else {
            $origin = $rawOrigin;
        }

        $sep = (strpos($origin, '?') !== false) ? '&' : '?';

        $preference = [
            "items" => [
                [
                    "id" => "sub_" . $planId,
                    "title" => "Membresía Mensual Padelblox - " . $planName,
                    "quantity" => 1,
                    "currency_id" => "CLP",
                    "unit_price" => $amount
                ]
            ],
            "payer" => [
                "email" => !empty($data['payer_email']) ? $data['payer_email'] : "contacto@padelblox.cl"
            ],
            "back_urls" => [
                "success" => $origin . $sep . "payment_status=success&payment_type=sub_renewal&plan_id=" . $planId . "&user_id=" . $userId,
                "failure" => $origin . $sep . "payment_status=failure&payment_type=sub_renewal&plan_id=" . $planId . "&user_id=" . $userId,
                "pending" => $origin . $sep . "payment_status=pending&payment_type=sub_renewal&plan_id=" . $planId . "&user_id=" . $userId
            ],
            "auto_return" => "approved",
            "external_reference" => json_encode([
                "type" => "subscription_renewal",
                "user_id" => $userId,
                "coach_id" => $userId,
                "plan_id" => $planId,
                "amount" => $amount
            ]),
            "notification_url" => "https://padelblox.cl/api/pagos/mp_webhook.php",
            "statement_descriptor" => "PADELBLOX",
            "binary_mode" => true
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . MP_ACCESS_TOKEN,
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($preference));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return $decoded;
        } else {
            error_log("Mercado Pago Subscription Preference Error: " . $response);
            return [
                "error" => true,
                "status" => $httpCode,
                "message" => $decoded['message'] ?? "Error desconocido al generar Checkout Mercado Pago (Code: $httpCode)",
                "raw" => $response
            ];
        }
    }
}

