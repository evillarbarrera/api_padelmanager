<?php
/**
 * PADELBLOX API - LOGOUT (CIERRE DE SESIÓN)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/check_session.php';

// Validar que la sesión actual sea válida
$currentUser = validateActiveSession($db);

// Invalidar el token de la sesión en la base de datos
$stmt = $db->prepare("UPDATE usuarios SET current_session_token = NULL WHERE id = ?");
$stmt->bind_param("i", $currentUser['id']);
$stmt->execute();

http_response_code(200);
echo json_encode([
    'success' => true,
    'message' => 'Sesión cerrada correctamente'
]);
exit;
