<?php
/**
 * Auth Helper for Training Padel Academy
 * Validates the custom token format: ID|padel_academy encoded in Base64.
 */

function validateToken() {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

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
