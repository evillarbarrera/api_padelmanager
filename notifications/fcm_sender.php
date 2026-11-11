<?php
// fcm_sender.php
// Función pura en PHP para evitar Composer dependency requests (kreait/firebase) y usar FCM HTTP v1

if (!function_exists('get_fcm_access_token')) {
    function get_fcm_access_token(&$diagError = null) {
        $keyFile = __DIR__ . '/../academia-padel-firebase-adminsdk-fbsvc-e982fff4f3.json';
        if (!file_exists($keyFile)) {
            // Buscar automáticamente cualquier archivo de service account descargado de Firebase en api/
            $matches = glob(__DIR__ . '/../*firebase-adminsdk*.json');
            if (!empty($matches)) {
                $keyFile = $matches[0];
            }
        }

        if (!file_exists($keyFile)) {
            $diagError = "No se encuentra el archivo JSON de Service Account en: $keyFile";
            error_log("FCM Sender Error: $diagError");
            return false;
        }

        $keyContent = file_get_contents($keyFile);
        $keyData = json_decode($keyContent, true);
        if (!$keyData || !isset($keyData['private_key'])) {
            $diagError = "El archivo JSON existe pero no contiene 'private_key' válida.";
            error_log("FCM Sender Error: $diagError");
            return false;
        }
        
        // Función canónica Base64Url (RFC 7515)
        $b64Url = function($data) {
            return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        };

        // Create JWT Header con kid de la Service Account
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT'
        ];
        if (!empty($keyData['private_key_id'])) {
            $header['kid'] = $keyData['private_key_id'];
        }

        // Clock Skew tolerance: restar 60s al iat
        $now = time();
        $claim = [
            'iss' => $keyData['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now - 60
        ];

        $headerJson = json_encode($header, JSON_UNESCAPED_SLASHES);
        $claimJson = json_encode($claim, JSON_UNESCAPED_SLASHES);

        $base64UrlHeader = $b64Url($headerJson);
        $base64UrlClaim = $b64Url($claimJson);
        $signatureInput = $base64UrlHeader . "." . $base64UrlClaim;

        $privKeyObj = openssl_pkey_get_private($keyData['private_key']);
        if (!$privKeyObj) {
            $openSslErrors = [];
            while ($msg = openssl_error_string()) {
                $openSslErrors[] = $msg;
            }
            $diagError = "openssl_pkey_get_private no pudo parsear la private_key: " . implode("; ", $openSslErrors);
            error_log("FCM Sender Error: $diagError");
            return false;
        }

        $signature = '';
        $signSuccess = openssl_sign($signatureInput, $signature, $privKeyObj, OPENSSL_ALGO_SHA256);
        if (!$signSuccess) {
            $openSslErrors = [];
            while ($msg = openssl_error_string()) {
                $openSslErrors[] = $msg;
            }
            $diagError = "openssl_sign falló con OPENSSL_ALGO_SHA256: " . implode("; ", $openSslErrors);
            error_log("FCM Sender Error: $diagError");
            return false;
        }

        $jwt = $signatureInput . "." . $b64Url($signature);

        // Send to Google OAuth2 API
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ]));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode != 200) {
            $diagError = "Google OAuth2 rechazo la peticion (HTTP $httpCode): " . ($response ?: $curlError);
            error_log("FCM Token Error: $diagError");
            return false;
        }

        $resData = json_decode($response, true);
        $token = $resData['access_token'] ?? false;
        if (!$token) {
            $diagError = "Google respondio 200 pero no vino 'access_token': $response";
            return false;
        }
        return $token;
    }
}

if (!function_exists('send_fcm_push')) {
    function send_fcm_push($deviceTokens, $title, $body, $data = []) {
        if (empty($deviceTokens)) return 0;
        
        $accessToken = get_fcm_access_token();
        if (!$accessToken) {
            error_log("FCM Sender Error: Could not get access token.");
            return false;
        }

        // Load project id from json
        $keyFile = __DIR__ . '/../academia-padel-firebase-adminsdk-fbsvc-e982fff4f3.json';
        if (!file_exists($keyFile)) {
            $matches = glob(__DIR__ . '/../*firebase-adminsdk*.json');
            if (!empty($matches)) {
                $keyFile = $matches[0];
            }
        }
        $keyData = file_exists($keyFile) ? json_decode(file_get_contents($keyFile), true) : null;
        $projectId = $keyData['project_id'] ?? 'academia-padel';

        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ];

        $successCount = 0;
        $errors = [];

        foreach ($deviceTokens as $token) {
            if (empty($token)) continue;

            // Ensure data counts are strings for FCM
            $dataPayload = [];
            foreach ($data as $k => $v) {
                $dataPayload[$k] = (string)$v;
            }

            $message = [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $title,
                        'body' => $body
                    ],
                    'data' => (object)$dataPayload,
                    'android' => [
                        'priority' => 'high',
                        'notification' => [
                            'sound' => 'default',
                            'click_action' => 'FCM_PLUGIN_ACTIVITY',
                            'icon' => 'fcm_push_icon'
                        ]
                    ],
                    'apns' => [
                        'headers' => [
                            'apns-priority' => '10',
                            'apns-push-type' => 'alert'
                        ],
                        'payload' => [
                            'aps' => [
                                'alert' => [
                                    'title' => $title,
                                    'body' => $body
                                ],
                                'sound' => 'default',
                                'badge' => 1,
                                'mutable-content' => 1
                            ]
                        ]
                    ]
                ]
            ];

            $jsonPayload = json_encode($message);
            error_log("FCM SENDING: " . $jsonPayload);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode == 200) {
                $successCount++;
            } else {
                $errors[] = "Token: " . substr($token, 0, 25) . "... - Code: $httpCode - Response: $response";
                error_log("FCM Send Warning: Token error (HTTP $httpCode): " . substr($response, 0, 150));
            }
        }

        if (!empty($errors)) {
            file_put_contents(__DIR__ . '/fcm_errors.log', date('Y-m-d H:i:s') . " - Errors:" . PHP_EOL . implode(PHP_EOL, $errors) . PHP_EOL, FILE_APPEND);
        }

        return $successCount;
    }
}
?>
