<?php
/**
 * PADELBLOX API - MIDDLEWARE / ENDPOINT DE VALIDACIÓN DE SESIÓN ÚNICA
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/auth_helper.php';

$user = validateActiveSession($conn);

echo json_encode([
    'success' => true,
    'user' => $user
]);
exit;
