<?php
require_once "db.php";
require_once "notifications/notificaciones_helper.php";

header("Content-Type: application/json");

$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 3;

$titulo = "🚀 Prueba de Sistema";
$mensaje = "Hola! Esta es una notificación de prueba para verificar que tu dispositivo está recibiendo mensajes correctamente.";

$resultado = notifyUser($conn, $user_id, $titulo, $mensaje, 'test_push');

if ($resultado) {
    echo json_encode([
        "ok" => true,
        "message" => "Intento de envío realizado para el usuario $user_id",
        "details" => "Revisa el archivo api_training/notifications/notify_user.log para ver si se encontraron tokens y si FCM respondió con éxito."
    ]);
} else {
    echo json_encode([
        "ok" => false,
        "message" => "Error al intentar enviar la notificación."
    ]);
}
?>
