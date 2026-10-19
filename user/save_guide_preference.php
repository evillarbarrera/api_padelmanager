<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

function ensureClubGuideSeenColumn($conn) {
    if (!$conn) return;
    $check = $conn->query("SHOW COLUMNS FROM usuarios LIKE 'club_guide_seen'");
    if ($check && $check->num_rows == 0) {
        $conn->query("ALTER TABLE usuarios ADD club_guide_seen TINYINT(1) DEFAULT 0");
    }
}

$data = json_decode(file_get_contents("php://input"), true);
$userId = intval($data['user_id'] ?? 0);
$seen = isset($data['seen']) ? intval($data['seen']) : 1;

if (!$userId) {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $headers['X-Authorization'] ?? '';
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $decoded = base64_decode($matches[1]);
        $parts = explode('|', $decoded);
        $userId = intval($parts[0] ?? 0);
    }
}

if ($userId > 0) {
    ensureClubGuideSeenColumn($conn);
    $stmt = $conn->prepare("UPDATE usuarios SET club_guide_seen = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $seen, $userId);
        $stmt->execute();
    }
}

ob_clean();
echo json_encode([
    "success" => true,
    "user_id" => $userId,
    "club_guide_seen" => $seen
]);
