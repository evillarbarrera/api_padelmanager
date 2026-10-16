<?php
/**
 * PADELBLOX API - LOGOUT (CIERRE DE SESIÓN MULTIDISPOSITIVO)
 * Revoca el token del dispositivo actual sin afectar a los otros dispositivos activos.
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

$userId = validateToken();

// Obtener token crudo del header
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_AUTHORIZATION'] ?? $_GET['token'] ?? '';
$rawToken = '';
if (strpos($auth, 'Bearer ') === 0) {
    $rawToken = trim(substr($auth, 7));
} else {
    $rawToken = trim($auth);
}

if ($userId && !empty($rawToken) && isset($conn) && $conn) {
    revokeUserSessionToken($conn, $userId, $rawToken);
}

echo json_encode([
    'success' => true,
    'message' => 'Sesión cerrada correctamente en este dispositivo'
]);
exit;
