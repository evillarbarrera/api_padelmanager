<?php
header("Content-Type: text/plain");
$files = [
    'notificaciones/notify_user.log',
    'notificaciones/fcm_errors.log',
    'notifications/notify_user.log',
    'notifications/fcm_errors.log'
];

foreach ($files as $f) {
    if (file_exists($f)) {
        echo "--- File: $f ---\n";
        echo file_get_contents($f);
        echo "\n\n";
    } else {
        echo "--- File: $f (NOT FOUND) ---\n";
    }
}
?>
