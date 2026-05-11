<?php
require_once __DIR__ . '/../db.php';

$userId = 3;
$sql = "SELECT id, token_fcm, platform FROM usuarios_tokens WHERE id_usuario = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

echo "Tokens for User ID $userId:\n";
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "ID: " . $row['id'] . " | Platform: " . $row['platform'] . " | Token: " . substr($row['token_fcm'], 0, 20) . "...\n";
    }
} else {
    echo "No tokens found for User ID $userId.\n";
}

$stmt->close();
$conn->close();
?>
