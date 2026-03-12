<?php
header('Content-Type: application/json');

$files = [
    'notify_user.log',
    'fcm_errors.log',
    'fcm_debug.log'
];

$results = [];

foreach ($files as $file) {
    if (file_exists($file)) {
        $results[$file] = file_get_contents($file);
    } else {
        $results[$file] = "File not found";
    }
}

echo json_encode($results, JSON_PRETTY_PRINT);
