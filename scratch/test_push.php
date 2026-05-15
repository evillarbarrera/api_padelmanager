<?php
require_once "../db.php";
require_once "../notifications/notificaciones_helper.php";

$userId = 3; // ID del usuario a probar
$clean = isset($_GET['clean']) && $_GET['clean'] == '1';

if ($clean) {
    echo "Limpiando tokens antiguos para User ID: $userId...\n";
    $conn->query("DELETE FROM fcm_tokens WHERE user_id = $userId");
    echo "Tokens eliminados. Ahora abre el App para registrar el nuevo token.\n";
    exit;
}

$titulo = "🎾 Prueba de Notificación";
$mensaje = "Hola! Esta es una prueba técnica para verificar que tus notificaciones push funcionan correctamente.";

echo "Iniciando prueba para User ID: $userId...\n";

$result = notifyUser($conn, $userId, $titulo, $mensaje, 'prueba_tecnica');

if ($result) {
    echo "Comando enviado correctamente. Revisa el log 'api_training/notifications/notify_user.log' para ver los detalles del envío.\n";
} else {
    echo "Error al intentar enviar la notificación.\n";
}
?>
