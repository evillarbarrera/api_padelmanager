<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Mostrar todos los errores
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    // Probar conexión a BD
    require_once 'db.php';
    
    // Verificar que mysqli existe
    if (!isset($mysqli)) {
        throw new Exception("mysqli no está definido");
    }
    
    // Verificar que la tabla existe
    $result = $mysqli->query("DESCRIBE notificaciones");
    if (!$result) {
        throw new Exception("Tabla notificaciones no existe: " . $mysqli->error);
    }
    
    // Intentar insertar un registro de prueba
    $userId = 1;
    $titulo = "Test";
    $mensaje = "Mensaje de prueba";
    $tipo = "test";
    $fechaRef = null;
    
    $stmt = $mysqli->prepare("
        INSERT INTO notificaciones (user_id, titulo, mensaje, tipo, fecha_referencia, leida)
        VALUES (?, ?, ?, ?, ?, 0)
    ");
    
    if (!$stmt) {
        throw new Exception("Error preparando statement: " . $mysqli->error);
    }
    
    $stmt->bind_param('issss', $userId, $titulo, $mensaje, $tipo, $fechaRef);
    
    if (!$stmt->execute()) {
        throw new Exception("Error ejecutando insert: " . $stmt->error);
    }
    
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'message' => 'Prueba exitosa - inserción funciona correctamente'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
?>
