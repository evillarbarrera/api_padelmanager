<?php
/**
 * Auth Helper for Training Padel Academy
 * Validates the custom token format: ID|padel_academy encoded in Base64.
 */

function validateToken() {
    // 1. Get headers via getallheaders (Apache) if available
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    
    // 2. Fallback to standard Server variables for Authorization and CUSTOM X-Authorization
    $auth = '';
    
    // Check various sources for the standard token
    if (isset($headers['Authorization'])) {
        $auth = $headers['Authorization'];
    } elseif (isset($headers['authorization'])) {
        $auth = $headers['authorization'];
    } elseif (isset($headers['X-Authorization'])) {
        $auth = $headers['X-Authorization'];
    } elseif (isset($headers['x-authorization'])) {
        $auth = $headers['x-authorization'];
    } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['HTTP_X_AUTHORIZATION'])) {
        $auth = $_SERVER['HTTP_X_AUTHORIZATION'];
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
