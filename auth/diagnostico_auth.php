<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

$headers = function_exists('getallheaders') ? getallheaders() : [];

echo json_encode([
    "method" => $_SERVER['REQUEST_METHOD'],
    "all_headers" => $headers,
    "server_vars" => [
        "HTTP_AUTHORIZATION" => $_SERVER['HTTP_AUTHORIZATION'] ?? 'missing',
        "HTTP_X_AUTHORIZATION" => $_SERVER['HTTP_X_AUTHORIZATION'] ?? 'missing',
        "REDIRECT_HTTP_AUTHORIZATION" => $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? 'missing'
    ],
    "user_agent" => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
    "HASH_SQL_PARA_JAVIER" => password_hash("Padel15893", PASSWORD_DEFAULT)
], JSON_PRETTY_PRINT);
