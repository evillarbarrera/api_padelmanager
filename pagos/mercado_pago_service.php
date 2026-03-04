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
                "reserva_id" => $data['reserva_id'] ?? null
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
}

