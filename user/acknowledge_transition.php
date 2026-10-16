<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";
require_once "../db_helpers/user_consents_log.php";

$data = json_decode(file_get_contents("php://input"), true);
$user_id = $data['user_id'] ?? 0;

if (!$user_id) {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $headers['X-Authorization'] ?? '';
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $decoded = base64_decode($matches[1]);
        $parts = explode('|', $decoded);
        $user_id = intval($parts[0] ?? 0);
    }
}

if ($user_id) {
    ensureUserConsentsTable($conn);
    $stmt = $conn->prepare("UPDATE usuarios SET brand_transition_acknowledged = 1 WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    
    registrarConsentimiento($conn, $user_id, 'brand_transition_ack', 1, 'v1.0');
}

ob_clean();
echo json_encode(["success" => true]);
