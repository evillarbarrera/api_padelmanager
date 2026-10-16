<?php
// Cron job execution for scheduled WhatsApp Court Availability broadcasts
// Set cron in cPanel: */5 * * * * php /path/to/api_training/cron/send_whatsapp_court_broadcasts.php

require_once "../db.php";

$hora_actual = date('H:i');
$dia_semana_hoy = (string)date('w'); // 0=Dom, 1=Lun, 2=Mar... 6=Sab
$fecha_hoy = date('Y-m-d');

// Ensure log table exists
$sqlLogTable = "CREATE TABLE IF NOT EXISTS club_whatsapp_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    club_id INT NOT NULL,
    programacion_id INT NOT NULL,
    hora_ejecucion VARCHAR(10),
    slots_libres_count INT DEFAULT 0,
    mensaje_enviado TEXT,
    webhook_respuesta TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($sqlLogTable);

// Find matching active schedules for current time
$sql = "SELECT p.*, c.nombre as club_nombre 
        FROM club_whatsapp_programacion p 
        JOIN clubes c ON p.club_id = c.id 
        WHERE p.activo = 1 AND p.hora_envio = '$hora_actual'";

$res = $conn->query($sql);

if (!$res || $res->num_rows === 0) {
    echo "No hay difusiones de canchas programadas para la hora actual ($hora_actual).\n";
    exit;
}

while ($sched = $res->fetch_assoc()) {
    $club_id = $sched['club_id'];
    $club_nombre = $sched['club_nombre'];
    $sched_id = $sched['id'];
    $dias_semana_array = !empty($sched['dias_semana']) ? explode(',', $sched['dias_semana']) : ['0','1','2','3','4','5','6'];
    $max_envios = (int)$sched['max_envios'];
    $envios_realizados = (int)$sched['envios_realizados'];

    // Validar si hoy le corresponde según días de la semana seleccionados
    if (!in_array($dia_semana_hoy, $dias_semana_array)) {
        echo "Programación ID $sched_id omitida hoy ($dia_semana_hoy no está en días permitidos).\n";
        continue;
    }

    // Validar si alcanzó el límite máximo de envíos programados
    if ($max_envios > 0 && $envios_realizados >= $max_envios) {
        $conn->query("UPDATE club_whatsapp_programacion SET activo = 0 WHERE id = $sched_id");
        echo "Programación ID $sched_id completó su límite de $max_envios envíos y ha sido desactivada.\n";
        continue;
    }

    // 1. Obtener canchas del club
    $resC = $conn->query("SELECT id, nombre FROM canchas WHERE club_id = $club_id");
    $canchas = [];
    while ($c = $resC->fetch_assoc()) {
        $canchas[] = $c;
    }

    $mapCanchas = [];
    $totalLibres = 0;

    foreach ($canchas as $cancha) {
        $cid = $cancha['id'];
        $cnombre = $cancha['nombre'];

        $inicio = strtotime("07:00:00");
        $fin = strtotime("23:30:00");
        $bloque = 30 * 60;

        for ($t = $inicio; $t < $fin; $t += $bloque) {
            $h_inicio = date('H:i:s', $t);

            $sqlCheck = "SELECT id FROM reservas_cancha WHERE cancha_id = $cid AND fecha = '$fecha_hoy' AND estado != 'Cancelada' AND ((hora_inicio <= '$h_inicio' AND hora_fin > '$h_inicio'))";
            $resCheck = $conn->query($sqlCheck);

            if ($resCheck && $resCheck->num_rows === 0) {
                if (!isset($mapCanchas[$cnombre])) {
                    $mapCanchas[$cnombre] = [];
                }
                $rFormat = date('H:i', $t) . ' - ' . date('H:i', $t + $bloque);
                if (!in_array($rFormat, $mapCanchas[$cnombre])) {
                    $mapCanchas[$cnombre][] = $rFormat;
                    $totalLibres++;
                }
            }
        }
    }

    // 2. Construir mensaje de disponibilidad en vivo
    $fechaTxt = ucfirst(date('l j \d\e F \d\e Y'));
    $msg = "🎾 *" . strtoupper($club_nombre) . "*\n";
    $msg .= "📅 *Disponibilidad en Vivo:* $fechaTxt\n";
    if (!empty($sched['titulo_custom'])) {
        $msg .= "\n" . $sched['titulo_custom'] . "\n";
    }

    if (count($mapCanchas) > 0) {
        foreach ($mapCanchas as $cName => $hours) {
            $msg .= "\n📍 *$cName*\n";
            foreach ($hours as $h) {
                $msg .= "• $h\n";
            }
        }
    } else {
        $msg .= "\n⚠️ *No se encontraron turnos libres para hoy en este momento.*\n";
    }

    if (!empty($sched['promo_custom'])) {
        $msg .= "\n" . $sched['promo_custom'] . "\n";
    }

    $msg .= "\n📲 *¡Reserva tu turno ahora antes de que se agoten!*\n";
    $msg .= "👉 https://padelmanager.cl/#/clubes-reservar\n";
    if (!empty($sched['telefono_contacto'])) {
        $msg .= "📞 Contacto: " . $sched['telefono_contacto'] . "\n";
    }

    // 3. Si hay Webhook configurado, enviar el mensaje automáticamente
    $respStr = "Mensaje generado localmente";
    if (!empty($sched['webhook_url'])) {
        $ch = curl_init($sched['webhook_url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            "club_id" => $club_id,
            "club_nombre" => $club_nombre,
            "message" => $msg,
            "total_slots_libres" => $totalLibres
        ]));
        $respStr = curl_exec($ch);
        curl_close($ch);
    }

    // Incrementar contador de envíos
    $conn->query("UPDATE club_whatsapp_programacion SET envios_realizados = envios_realizados + 1 WHERE id = $sched_id");

    // Desactivar si alcanzó el límite tras este envío
    if ($max_envios > 0 && ($envios_realizados + 1) >= $max_envios) {
        $conn->query("UPDATE club_whatsapp_programacion SET activo = 0 WHERE id = $sched_id");
    }

    // Log de auditoría
    $stmtLog = $conn->prepare("INSERT INTO club_whatsapp_logs (club_id, programacion_id, hora_ejecucion, slots_libres_count, mensaje_enviado, webhook_respuesta) VALUES (?, ?, ?, ?, ?, ?)");
    $stmtLog.bind_param("iisiss", $club_id, $sched_id, $hora_actual, $totalLibres, $msg, $respStr);
    $stmtLog->execute();

    echo "Difusión ejecutada para Club $club_nombre ($club_id) a las $hora_actual. Envíos: " . ($envios_realizados + 1) . "/" . ($max_envios > 0 ? $max_envios : '∞') . ". Slots libres: $totalLibres.\n";
}
?>
