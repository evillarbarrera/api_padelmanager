<?php
/**
 * PADELBLOX API - LOGIN CON CONTROL DE SESIÓN ÚNICA
 */

require_once __DIR__ . '/../config.php';

// Obtener datos del cuerpo de la petición JSON
$input = json_decode(file_get_contents('php://input'), true);

$usuario = isset($input['usuario']) ? trim($input['usuario']) : '';
$password = isset($input['password']) ? trim($input['password']) : '';

if (empty($usuario) || empty($password)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Usuario y contraseña son requeridos.'
    ]);
    exit;
}

// Conexión a la base de datos (Ajustar con tus credenciales de producción)
/*
$db = new mysqli("localhost", "USUARIO_DB", "PASSWORD_DB", "c2632100_manager");
if ($db->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos']);
    exit;
}
$db->set_charset("utf8mb4");
*/

// Búsqueda del usuario en la tabla 'usuarios'
$stmt = $db->prepare("SELECT id, nombre, apellido, usuario, password, rol, club_id, foto_perfil, subscription_status FROM usuarios WHERE usuario = ? OR email = ? LIMIT 1");
$stmt->bind_param("ss", $usuario, $usuario);
$stmt->execute();
$result = $stmt->get_result();

if ($user = $result->fetch_assoc()) {
    // Verificar contraseña (password_verify o md5 según tu hashing actual)
    $passwordValid = password_verify($password, $user['password']) || (md5($password) === $user['password']);
    
    if ($passwordValid) {
        // GENERACIÓN DE TOKEN DE SESIÓN ÚNICA (Invalidará la sesión en cualquier otro dispositivo)
        $sessionToken = bin2hex(random_bytes(32));
        $now = date('Y-m-d H:i:s');
        
        // Actualizar la tabla 'usuarios' con el token activo de esta sesión
        $updateStmt = $db->prepare("UPDATE usuarios SET current_session_token = ?, last_login_at = ? WHERE id = ?");
        $updateStmt->bind_param("ssi", $sessionToken, $now, $user['id']);
        $updateStmt->execute();
        
        // Quitar la contraseña antes de devolver la respuesta
        unset($user['password']);
        $user['token'] = $sessionToken;
        $user['current_session_token'] = $sessionToken;
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Inicio de sesión exitoso',
            'user' => $user,
            'token' => $sessionToken
        ]);
        exit;
    }
}

http_response_code(401);
echo json_encode([
    'success' => false,
    'message' => 'Usuario o contraseña incorrectos.'
]);
exit;
