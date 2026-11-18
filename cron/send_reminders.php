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
$query = "SELECT id, user_id, titulo, mensaje, tipo, fecha_programada " . ($hasReservaIdCol ? ", reserva_id" : "") . " 
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
    $fechaProg = $row['fecha_programada'] ?? date('Y-m-d H:i:s');

    // 1. Si tiene reserva_id, verificar exhaustivamente que la reserva/cancha siga activa y no cancelada
    if ($reservaId > 0) {
        $isCancelled = false;

        // Comprobar en reservas_cancha (canchas / partidos amistosos)
        $stCheckCancha = $conn->prepare("SELECT estado FROM reservas_cancha WHERE id = ?");
        if ($stCheckCancha) {
            $stCheckCancha->bind_param("i", $reservaId);
            $stCheckCancha->execute();
            $resC = $stCheckCancha->get_result()->fetch_assoc();
            $stCheckCancha->close();
            if ($resC) {
                $stC = strtolower(trim($resC['estado'] ?? ''));
                if (in_array($stC, ['cancelada', 'cancelado', 'anulada', 'anulado', 'rechazada', 'eliminada', 'bloqueado', 'bloqueada']) || strpos($stC, 'cancel') !== false) {
                    $isCancelled = true;
                }
            }
        }

        // Comprobar en reservas (clases de entrenamiento)
        if (!$isCancelled) {
            $stVal = $conn->prepare("SELECT estado FROM reservas WHERE id = ?");
            if ($stVal) {
                $stVal->bind_param("i", $reservaId);
                $stVal->execute();
                $resVal = $stVal->get_result()->fetch_assoc();
                $stVal->close();
                if ($resVal) {
                    $stR = strtolower(trim($resVal['estado'] ?? ''));
                    if (in_array($stR, ['cancelada', 'cancelado', 'bloqueado', 'anulado']) || strpos($stR, 'cancel') !== false) {
                        $isCancelled = true;
                    }
                }
            }
        }

        if ($isCancelled) {
            // Reserva cancelada: descartar recordatorio sin enviar
            $conn->query("UPDATE recordatorios_programados SET enviado = 2, enviado_at = NOW() WHERE id = $id");
            continue;
        }
    }

    // 2. Control de duplicados estricto: evitar enviar múltiples recordatorios idénticos hoy
    $identificador = ($reservaId > 0) ? "#$reservaId" : $titulo;
    $stmtDup = @$conn->prepare("
        SELECT id FROM notificaciones 
        WHERE user_id = ? 
          AND (
              tipo = ? 
              OR (tipo LIKE 'recordatorio%' AND fecha_referencia = ?)
          )
          AND (
              fecha_referencia = ? 
              OR (DATE(fecha) = CURDATE() AND mensaje LIKE ?)
              OR (DATE(fecha) = CURDATE() AND fecha_referencia LIKE ?)
          )
        LIMIT 1
    ");
    if ($stmtDup) {
        $likePattern = "%" . $identificador . "%";
        $fechaPattern = substr($fechaProg, 0, 10) . "%";
        $stmtDup->bind_param('isssss', $userId, $tipo, $fechaProg, $fechaProg, $likePattern, $fechaPattern);
        $stmtDup->execute();
        $resDup = $stmtDup->get_result();
        $isDuplicate = ($resDup && $resDup->num_rows > 0);
        $stmtDup->close();
        if ($isDuplicate) {
            // Ya fue notificado hoy para este evento: marcar como enviado y omitir duplicado
            $conn->query("UPDATE recordatorios_programados SET enviado = 1, enviado_at = NOW() WHERE id = $id");
            continue;
        }
    }

    echo "Enviando a Usuario $userId: $titulo\n";
    
    // Usar la función centralizada que ya maneja BD y FCM
    if (notifyUser($conn, $userId, $titulo, $mensaje, $tipo, $fechaProg)) {
        // Marcar como enviado
        $conn->query("UPDATE recordatorios_programados SET enviado = 1, enviado_at = NOW() WHERE id = $id");
        $count++;
    }
}

echo "--- Proceso finalizado. Total enviados: $count ---\n";
?>
