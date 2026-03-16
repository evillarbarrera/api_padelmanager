<?php
require_once '../db.php';
$result = $conn->query("SELECT * FROM fcm_tokens ORDER BY created_at DESC LIMIT 10");
$tokens = [];
while($row = $result->fetch_assoc()) {
    $tokens[] = $row;
}
echo json_encode($tokens, JSON_PRETTY_PRINT);
?>
