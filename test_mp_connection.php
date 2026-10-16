<?php
require_once "mercado_pago_service.php";
require_once "../db.php";

echo "--- Iniciando Prueba de Conexión Mercado Pago (V2) ---\n";

$dummyData = [
    'pack_id' => 999,
    'title' => 'Pack de Prueba PadelManager',
    'amount' => 1500,
    'jugador_id' => 3,
    'origin' => 'https://padelmanager.cl/test'
];

try {
    echo "Intentando crear preferencia con datos corregidos...\n";
    $result = MercadoPagoService::createPreference($dummyData);
    
    if ($result && isset($result['init_point'])) {
        echo "¡ÉXITO! Preferencia creada correctamente.\n";
        echo "ID de Preferencia: " . $result['id'] . "\n";
        echo "Punto de Inicio: " . $result['init_point'] . "\n";
    } else {
        echo "FALLO: No se recibió un punto de inicio.\n";
        echo "Respuesta del Servidor: " . json_encode($result) . "\n";
    }
} catch (Exception $e) {
    echo "ERROR CRITICO: " . $e->getMessage() . "\n";
}
?>
