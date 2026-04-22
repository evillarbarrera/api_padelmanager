<?php
require_once "auth/auth_helper.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

// Simular una validación de token y mostrar qué vio el servidor
$userId = validateToken();

echo json_encode([
    "status" => $userId ? "AUTHENTICATED" : "NOT_AUTHENTICATED",
    "user_found_id" => $userId,
    "debug" => [
        "method" => $_SERVER['REQUEST_METHOD'],
        "getAnyHeader_Authorization" => getAnyHeader('Authorization') ? "YES (starts with " . substr(getAnyHeader('Authorization'), 0, 10) . "...)" : "NO",
        "getAnyHeader_X_Authorization" => getAnyHeader('X-Authorization') ? "YES" : "NO",
        "server_http_auth" => isset($_SERVER['HTTP_AUTHORIZATION']) ? "YES" : "NO",
        "server_redirect_auth" => isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ? "YES" : "NO",
        "all_headers" => function_exists('getallheaders') ? getallheaders() : "not_available"
    ]
]);
