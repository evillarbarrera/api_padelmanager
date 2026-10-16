<?php
/**
 * PADELBLOX API - MIDDLEWARE DE VALIDACIÓN DE SESIÓN ÚNICA
 * 
 * Uso: Incluir este archivo al inicio de cualquier endpoint PHP protegido.
 * Ejemplo: require_once __DIR__ . '/../auth/check_session.php';
 *          $currentUser = validateActiveSession($db);
 */

require_once __DIR__ . '/../config.php';

function validateActiveSession($db) {
    // 1. Extraer el token de las cabeceras HTTP Authorization o X-Authorization
    $headers = getallheaders();
    $authHeader = '';
    
    foreach ($headers as $key => $value) {
        if (strtolower($key) === 'authorization' || strtolower($key) === 'x-authorization') {
            $authHeader = $value;
            break;
        }
    }
    
    $token = '';
    if (preg_match('/Bearer\s(\S+)/i', $authHeader, $matches)) {
        $token = $matches[1];
    } elseif (!empty($authHeader)) {
        $token = trim($authHeader);
    }
    
    if (empty($token)) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'code' => 'UNAUTHORIZED',
            'message' => 'Token de autenticación no proporcionado.'
        ]);
        exit;
    }
    
    // 2. Verificar que el token pertenezca al token activo o sea válido por ID
    $stmt = $db->prepare("SELECT id, nombre, usuario, rol, club_id FROM usuarios WHERE current_session_token = ? LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($user = $result->fetch_assoc()) {
        return $user; // Token válido: retorna el usuario autenticado
    }

    // Fallback: decodificar token para permitir múltiples dispositivos
    $tokenDecoded = base64_decode($token, true);
    if ($tokenDecoded) {
        $parts = explode('|', $tokenDecoded);
        if (count($parts) >= 2 && $parts[1] === 'padel_academy') {
            $userId = intval($parts[0]);
            if ($userId > 0) {
                $stmt2 = $db->prepare("SELECT id, nombre, usuario, rol, club_id FROM usuarios WHERE id = ? LIMIT 1");
                $stmt2->bind_param("i", $userId);
                $stmt2->execute();
                $res2 = $stmt2->get_result();
                if ($user2 = $res2->fetch_assoc()) {
                    return $user2;
                }
            }
        }
    }
    
    // 3. Token no válido
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'code' => 'UNAUTHORIZED',
        'message' => 'Token inválido o expirado.'
    ]);
    exit;
}
