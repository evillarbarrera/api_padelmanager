<?php
require_once '../db.php';
require_once '../notifications/notificaciones_helper.php';

header('Content-Type: application/json');

$debug = [];

// 1. Check database connection
if ($conn) {
    $debug['db_connection'] = 'OK';
} else {
    $debug['db_connection'] = 'FAILED';
}

// 2. Check fcm_tokens table
$resTokens = $conn->query("SELECT * FROM fcm_tokens ORDER BY id DESC LIMIT 5");
$tokens = [];
while ($row = $resTokens->fetch_assoc()) {
    $tokens[] = $row;
}
$debug['last_tokens'] = $tokens;
$debug['total_tokens'] = $conn->query("SELECT COUNT(*) as count FROM fcm_tokens")->fetch_assoc()['count'];

// 3. Test notifyUser for a specific user (if provided)
$testUserId = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;
if ($testUserId) {
    $res = notifyUser($conn, $testUserId, "Test Tool Notification", "Esta es una prueba desde el asistente", "test");
    $debug['notify_user_result'] = $res ? 'SUCCESS (Called notifyUser)' : 'FAILED';
}

// 4. Check for fcm_errors.log
$logFile = __DIR__ . '/fcm_errors.log';
if (file_exists($logFile)) {
    $debug['fcm_errors_log'] = file_get_contents($logFile);
} else {
    $debug['fcm_errors_log'] = 'No log file found at ' . $logFile;
}

echo json_encode($debug, JSON_PRETTY_PRINT);
