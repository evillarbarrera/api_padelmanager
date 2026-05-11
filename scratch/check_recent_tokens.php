<?php
require_once __DIR__ . '/../db.php';

$sql = "SELECT t.*, u.nombre 
        FROM fcm_tokens t
        JOIN usuarios u ON t.user_id = u.id 
        ORDER BY t.created_at DESC LIMIT 20";
$result = $conn->query($sql);

echo "Recent tokens found:\n";
while ($row = $result->fetch_assoc()) {
    echo "User: " . $row['nombre'] . " (ID: " . $row['user_id'] . ") - Token: " . substr($row['token'], 0, 20) . "... - Created: " . $row['created_at'] . "\n";
}

$conn->close();
?>
