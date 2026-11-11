<?php
// cron/send_reminders.php
// Script para enviar recordatorios programados (e.g. 24h antes)

require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../notifications/notificaciones_helper.php";

echo "--- Iniciando envío de recordatorios pendientes ---\n";

// 1. Expirar recordatorios con más de 3 horas de retraso para evitar spam de recordatorios obsoletos
@$conn->query("UPDATE recordatorios_programados SET enviado = 2, enviado_at = NOW() WHERE enviado = 0 AND fecha_programada < DATE_SUB(NOW(), INTERVAL 3 HOUR)");

$checkColRes = $conn->query("SHOW COLUMNS FROM recordatorios_programados LIKE 'reserva_id'");
$hasReservaIdCol = ($checkColRes && $checkColRes->num_rows > 0);

// 2. Buscar recordatorios para la ventana actual (últimas 3 horas)
$query = "SELECT id, user_id, titulo, mensaje, tipo " . ($hasReservaIdCol ? ", reserva_id" : "") . " 
          FROM recordatorios_programados 
          WHERE enviado = 0 
            AND fecha_programada <= NOW()
            AND fecha_programada >= DATE_SUB(NOW(), INTERVAL 3 HOUR)";

$res = $conn->query($query);

if (!$res) {
    die("Error query: " . $conn->error);
}

$count = 0;
while ($row = $res->fetch_assoc()) {
    $id = intval($row['id']);
    $userId = intval($row['user_id']);
    $titulo = $row['titulo'];
    $mensaje = $row['mensaje'];
    $tipo = $row['tipo'];
    $reservaId = isset($row['reserva_id']) ? intval($row['reserva_id']) : 0;

    // Si tiene reserva_id, verificar que la clase siga activa
    if ($reservaId > 0) {
        $stVal = $conn->prepare("SELECT estado FROM reservas WHERE id = ?");
        if ($stVal) {
            $stVal->bind_param("i", $reservaId);
            $stVal->execute();
            $resVal = $stVal->get_result()->fetch_assoc();
            $stVal->close();
            if ($resVal && in_array(strtolower($resVal['estado']), ['cancelada', 'cancelado', 'bloqueado'])) {
                // Clase cancelada: descartar recordatorio
                $conn->query("UPDATE recordatorios_programados SET enviado = 2, enviado_at = NOW() WHERE id = $id");
                continue;
            }
        }
    }

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
