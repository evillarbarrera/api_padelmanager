<?php
require_once '../db.php';
header('Content-Type: application/json');

$userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 3;

$sql = "SELECT id, token, created_at FROM fcm_tokens WHERE user_id = ? ORDER BY created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

$tokens = [];
while ($row = $result->fetch_assoc()) {
    $tokens[] = [
        "id" => $row['id'],
        "token_snippet" => substr($row['token'], 0, 20) . "...",
        "created_at" => $row['created_at']
    ];
}

echo json_encode([
    "user_id" => $userId,
    "total_tokens" => count($tokens),
    "tokens" => $tokens,
    "current_server_time" => date('Y-m-d H:i:s')
], JSON_PRETTY_PRINT);
