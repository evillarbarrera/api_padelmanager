<?php
require_once 'whatsapp_service.php';

$miCelular = '56934516352';
$fecha = date('d/m/Y');
$hora = date('H:i');
$jugador = 'Emmanuel Villar';
$entrenador = 'Coach Central';
$vars = [$fecha, $hora, $jugador, $entrenador];

echo "<html><body style='font-family:sans-serif; padding: 20px;'>";
echo "<h1>Panel de Pruebas WhatsApp Cloud API</h1>";

// --- PRUEBA 1: RESERVA CONFIRMADA ---
echo "<div style='background:#f0f9ff; padding: 15px; border-radius: 10px; margin-bottom: 20px; border: 1px solid #bae6fd;'>";
echo "<h2>1. Prueba: Confirmación</h2>";
$res1 = enviarWhatsApp($miCelular, 'reserva_confirmada', 'es_CL', $vars);
echo "<b>Resultado:</b> " . ($res1['success'] ? "<span style='color:green'>EXITOSO</span>" : "<span style='color:red'>FALLIDO</span>") . "<br>";
if (!$res1['success']) echo "<b>Error:</b> " . json_encode($res1['error']) . "<br>";
echo "</div>";

// --- PRUEBA 2: RESERVA CANCELADA ---
echo "<div style='background:#fef2f2; padding: 15px; border-radius: 10px; margin-bottom: 20px; border: 1px solid #fecaca;'>";
echo "<h2>2. Prueba: Cancelación</h2>";
$res2 = enviarWhatsApp($miCelular, 'reserva_cancelada', 'es_CL', $vars);
echo "<b>Resultado:</b> " . ($res2['success'] ? "<span style='color:green'>EXITOSO</span>" : "<span style='color:red'>FALLIDO</span>") . "<br>";
if (!$res2['success']) echo "<b>Error:</b> " . json_encode($res2['error']) . "<br>";
echo "</div>";

// --- REGISTROS ---
echo "<h2>Registro de Logs (whatsapp_log.txt)</h2>";
$logFile = __DIR__ . '/whatsapp_log.txt';

if (!is_writable(__DIR__)) {
    echo "<p style='color:red; font-weight:bold;'>⚠️ ERROR: La carpeta de notificaciones NO tiene permisos de escritura. No se pueden guardar logs.</p>";
}

if (file_exists($logFile)) {
    echo "<p>Últimos movimientos registrados:</p>";
    echo "<pre style='background:#eee; padding:10px; border-radius:5px; max-height: 400px; overflow: auto;'>" . htmlspecialchars(file_get_contents($logFile)) . "</pre>";
} else {
    echo "<p>No existe el archivo de log todavía (se crea al primer envío).</p>";
}

echo "<hr>";
echo "<p style='font-size:0.8em; color:#777;'>Token configurado actualmente: " . substr(enviarWhatsApp('0','0')['token_debug'] ?? '...', 0, 20) . "...</p>";

echo "</body></html>";
?>
