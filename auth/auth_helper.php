<?php
/**
 * Auth Helper for Training Padel Academy
 * Validates the custom token format: ID|padel_academy encoded in Base64.
 */

function validateToken() {
    // 1. Get headers via getallheaders (Apache) if available
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    
    // Check headers case-insensitively
    foreach ($headers as $key => $value) {
        $keyLower = strtolower($key);
        if ($keyLower === 'authorization' || $keyLower === 'x-authorization') {
            $auth = $value;
            break;
        }
    }

    // Fallbacks to standard Server variables
    if (empty($auth)) {
        $possible_keys = [
            'HTTP_AUTHORIZATION', 
            'REDIRECT_HTTP_AUTHORIZATION', 
            'HTTP_X_AUTHORIZATION', 
            'REDIRECT_HTTP_X_AUTHORIZATION'
        ];
        foreach ($possible_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $auth = $_SERVER[$key];
                break;
            }
        }
    }

    // 🏆 ULTIMATE FALLBACK: Query Parameter (for Safari/Mobile bypass)
    if (empty($auth) && !empty($_GET['token'])) {
        $auth = 'Bearer ' . $_GET['token'];
    }

    if (empty($auth)) {
        return false;
    }

    if (strpos($auth, 'Bearer ') !== 0) {
        return false;
    }

    $tokenEncoded = substr($auth, 7);
    $tokenDecoded = base64_decode($tokenEncoded);

    if (!$tokenDecoded) {
        return false;
    }

    $parts = explode('|', $tokenDecoded);
    if (count($parts) !== 2) {
        return false;
    }

    $userId = intval($parts[0]);
    $secret = $parts[1];

    if ($secret !== 'padel_academy' || $userId <= 0) {
        return false;
    }

    return $userId;
}

function sendUnauthorized($details = "Token mismatch or missing") {
    http_response_code(401);
    echo json_encode([
        "error" => "Unauthorized",
        "details" => $details
    ]);
    exit;
}
?>
