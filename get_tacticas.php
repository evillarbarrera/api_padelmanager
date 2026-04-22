<?php
// TEST_DEBUG.php - Minimal version to bypass 500 error
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// ⚠️ Temporarily comment DB include to see if server responds
// include_once 'db.php';

echo json_encode([
    'success' => true,
    'message' => 'Servidor respondiendo correctamente (Modo Test)',
    'data' => [
        ['id' => 1, 'nombre' => 'Test de conexión', 'contenido_json' => '{}']
    ]
]);
?>
