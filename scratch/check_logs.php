<?php
// check_logs.php
header("Content-Type: text/plain");

$files = [
    '../notifications/notify_user.log',
    '../notifications/fcm_errors.log',
    '../notifications/fcm_token_error.log',
    '../notifications/token_requests.log',
    '../notifications/access_debug.log'
];

foreach ($files as $f) {
    $path = __DIR__ . '/' . $f;
    echo "--- FILE: $f (Last 50 lines) ---\n";
    if (file_exists($path)) {
        $lines = file($path);
        $last_lines = array_slice($lines, -50);
        echo implode("", $last_lines);
    } else {
        echo "File not found: $path\n";
    }
    echo "\n\n";
}

// Check database tokens
require_once "../db.php";
$userId = 3;
$res = $conn->query("SELECT * FROM fcm_tokens WHERE user_id = $userId");
echo "--- DB TOKENS FOR USER $userId ---\n";
while ($row = $res->fetch_assoc()) {
    print_r($row);
}

$resUser = $conn->query("SELECT id, nombre, fcm_token FROM usuarios WHERE id = $userId");
echo "\n--- LEGACY USER TOKEN FOR USER $userId ---\n";
while ($row = $resUser->fetch_assoc()) {
    print_r($row);
}
?>
