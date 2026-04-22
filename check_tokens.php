<?php
require_once "db.php";
$userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 3;
$res = $conn->query("SELECT * FROM fcm_tokens WHERE user_id = $userId ORDER BY created_at DESC");
echo "Tokens for User $userId:\n";
while($row = $res->fetch_assoc()) {
    print_r($row);
}
