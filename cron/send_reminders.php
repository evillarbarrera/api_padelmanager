<?php
// cron/send_reminders.php
// Script para enviar recordatorios programados (e.g. 24h antes)

require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../notifications/notificaciones_helper.php";

echo "--- Iniciando envío de recordatorios pendientes ---\n";

// Buscar recordatorios para hoy o días anteriores que no se hayan enviado
$query = "SELECT id, user_id, titulo, mensaje, tipo 
          FROM recordatorios_programados 
          WHERE enviado = 0 
          AND fecha_programada <= NOW()";

$res = $conn->query($query);

if (!$res) {
    die("Error query: " . $conn->error);
}

$count = 0;
while ($row = $res->fetch_assoc()) {
    $id = $row['id'];
    $userId = $row['user_id'];
    $titulo = $row['titulo'];
    $mensaje = $row['mensaje'];
    $tipo = $row['tipo'];

    echo "Enviando a Usuario $userId: $titulo\n";
    
    // Usar la función centralizada que ya maneja BD y FCM
    if (notifyUser($conn, $userId, $titulo, $mensaje, $tipo)) {
        // Marcar como enviado
        $conn->query("UPDATE recordatorios_programados SET enviado = 1, enviado_at = NOW() WHERE id = $id");
        $count++;
    }
}

echo "--- Proceso finalizado. Total enviados: $count ---\n";
?>
