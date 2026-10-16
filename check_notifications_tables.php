<?php
header('Content-Type: application/json');

require_once 'db.php';

// Verificar que las tablas existan
$tables = ['fcm_tokens', 'notificaciones', 'recordatorios_programados'];

foreach ($tables as $table) {
    $result = $mysqli->query("SHOW TABLES LIKE '$table'");
    if ($result->num_rows === 0) {
        echo json_encode(['error' => "Tabla $table no existe"]);
        exit;
    }
}

// Si llegó aquí, todas las tablas existen
echo json_encode([
    'success' => true,
    'message' => 'Todas las tablas de notificaciones existen',
    'tables' => $tables
]);
?>
