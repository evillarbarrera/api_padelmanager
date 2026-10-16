<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']) || 
           strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false;

echo json_encode([
    'status' => 'ok',
    'app' => 'Padelblox API',
    'version' => '2.4.0',
    'environment' => $isLocal ? 'local' : (strpos($_SERVER['REQUEST_URI'] ?? '', '/prd') !== false ? 'production' : 'development'),
    'server_time' => date('Y-m-d H:i:s'),
    'timezone' => date_default_timezone_get()
]);
