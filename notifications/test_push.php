<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/notificaciones_helper.php';

$userId = $_GET['user_id'] ?? 0;
if (!$userId) {
    die("Usage: ?user_id=XXX");
}

echo "Testing notification for User ID: $userId...\n";
$success = notifyUser($conn, $userId, "Test PadelManager", "Si recibes esto, las notificaciones funcionan correctamente.");

if ($success) {
    echo "Notification process finished. Check notify_user.log and fcm_errors.log for details.\n";
} else {
    echo "Notification process failed.\n";
}

$conn->close();
?>
