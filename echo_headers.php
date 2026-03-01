<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

echo json_encode([
    "headers" => getallheaders(),
    "server" => [
        "HTTP_AUTHORIZATION" => $_SERVER['HTTP_AUTHORIZATION'] ?? 'not set',
        "REDIRECT_HTTP_AUTHORIZATION" => $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? 'not set',
        "REQUEST_METHOD" => $_SERVER['REQUEST_METHOD']
    ]
]);
