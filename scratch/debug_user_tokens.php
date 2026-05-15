<?php
require_once "db.php";

$userId = $_GET['user_id'] ?? 0;
if (!$userId) {
    echo "Falta user_id";
    exit;
}

$sql = "SELECT * FROM fcm_tokens WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$res = $stmt->get_result();

$tokens = [];
while ($row = $res->fetch_assoc()) {
    $tokens[] = $row;
}

header('Content-Type: application/json');
echo json_encode([
    "user_id" => $userId,
    "tokens_found" => count($tokens),
    "tokens" => $tokens
], JSON_PRETTY_PRINT);
?>
