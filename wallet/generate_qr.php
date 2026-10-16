<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once "../db.php";

$input = json_decode(file_get_contents("php://input"), true);
if (!$input) {
    $input = $_POST;
}

$userId = isset($input['user_id']) ? intval($input['user_id']) : 0;

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "ID de usuario inválido o ausente."]);
    exit;
}

try {
    // Generate secure random values
    $nonce = bin2hex(random_bytes(16)); // 32 chars
    
    // Create JWT-like token structure or simply a signed string
    // In this case, we'll hash the nonce with a secret key for extra security
    $secretKey = "padelblox_secret_access_key_2026";
    $tokenHash = hash_hmac("sha256", $nonce . $userId, $secretKey);
    
    // Set expiration time (30 seconds from now)
    $expiresAt = date("Y-m-d H:i:s", time() + 30);
    
    // Deactivate previous unused sessions for this user
    $deactivateStmt = $pdo->prepare("UPDATE qr_sessions SET status = 'expired' WHERE user_id = ? AND status = 'unused'");
    $deactivateStmt->execute([$userId]);
    
    // Insert new QR session
    $insertStmt = $pdo->prepare("INSERT INTO qr_sessions (user_id, nonce, token_hash, expires_at, status) VALUES (?, ?, ?, ?, 'unused')");
    $insertStmt->execute([$userId, $nonce, $tokenHash, $expiresAt]);
    
    // Build QR payload
    // The QR contains: user_id, nonce, token_hash, and exp timestamp
    $qrPayload = json_encode([
        "sub" => $userId,
        "nonce" => $nonce,
        "token" => $tokenHash,
        "exp" => time() + 30
    ]);
    
    echo json_encode([
        "success" => true,
        "nonce" => $nonce,
        "token_hash" => $tokenHash,
        "expires_at" => $expiresAt,
        "qr_payload" => $qrPayload // This JSON string is what the client app will render as QR
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Error del servidor: " . $e->getMessage()]);
}
