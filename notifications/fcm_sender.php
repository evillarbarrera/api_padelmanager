<?php
// fcm_sender.php
// Función pura en PHP para evitar Composer dependency requests (kreait/firebase) y usar FCM HTTP v1

function get_fcm_access_token() {
    $keyFile = __DIR__ . '/../academia-padel-firebase-adminsdk-fbsvc-e982fff4f3.json';
    if (!file_exists($keyFile)) {
        error_log("FCM Sender Error: No se encuentra el JSON de Service Account en $keyFile");
        return false;
    }

    $keyData = json_decode(file_get_contents($keyFile), true);
    if (!isset($keyData['private_key'])) return false;
    
    // Create JWT
    $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
    $now = time();
    $claim = json_encode([
        'iss' => $keyData['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'exp' => $now + 3600,
        'iat' => $now
    ]);

    $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64UrlClaim = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($claim));
    $signatureInput = $base64UrlHeader . "." . $base64UrlClaim;

    $signature = '';
    openssl_sign($signatureInput, $signature, $keyData['private_key'], "sha256WithRSAEncryption");
    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    
    $jwt = $signatureInput . "." . $base64UrlSignature;

    // Send to Google OAuth2 API
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt
    ]));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode != 200) {
        error_log("FCM Token Error: " . $response);
        return false;
    }

    $resData = json_decode($response, true);
    return $resData['access_token'] ?? false;
}

function send_fcm_push($deviceTokens, $title, $body, $data = []) {
    if (empty($deviceTokens)) return 0;
    
    $accessToken = get_fcm_access_token();
    if (!$accessToken) return false;

    // Load project id from json
    $keyFile = __DIR__ . '/../academia-padel-firebase-adminsdk-fbsvc-e982fff4f3.json';
    $keyData = json_decode(file_get_contents($keyFile), true);
    $projectId = $keyData['project_id'];

    $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ];

    $successCount = 0;

    foreach ($deviceTokens as $token) {
        if (empty($token)) continue;

        $message = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body
                ],
                // For Ionic/Capacitor, sometimes data payload is captured in background
                'data' => (object)$data
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200) {
            $successCount++;
        } else {
            // log error
            error_log("FCM Request Error ($httpCode): " . $response);
            file_put_contents(__DIR__ . '/fcm_errors.log', date('Y-m-d H:i:s') . " - Error [$httpCode]: " . $response . PHP_EOL, FILE_APPEND);
        }
    }

    return $successCount;
}
?>
