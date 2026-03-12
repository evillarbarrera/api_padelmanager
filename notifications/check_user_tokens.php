<?php
require_once '../db.php';
header('Content-Type: application/json');

$userId = 3;
$res = $conn->query("SELECT * FROM fcm_tokens WHERE user_id = $userId");
$tokens = [];
while ($row = $res->fetch_assoc()) {
    $tokens[] = $row;
}
echo json_encode($tokens, JSON_PRETTY_PRINT);
