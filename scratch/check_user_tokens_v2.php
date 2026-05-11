<?php
require_once __DIR__ . '/../db.php';

$userId = 3;
$sql = "SELECT * FROM fcm_tokens WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

echo "Tokens for User ID $userId (from fcm_tokens):\n";
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "ID: " . $row['id'] . " | Token: " . substr($row['token'], 0, 20) . "...\n";
    }
} else {
    echo "No tokens found for User ID $userId in fcm_tokens table.\n";
}

$stmt->close();
$conn->close();
?>
