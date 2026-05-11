<?php
/**
 * Cron Job: Coach Activation Reminders
 * Purpose: Identify coaches with incomplete onboarding and send them a "nudge" (Push Notification).
 * Frequency: Recommended once a day.
 */

require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../notifications/notificaciones_helper.php";

// 1. Get coaches (allow test_user_id to force a specific coach)
$test_id = intval($_GET['test_user_id'] ?? 0);
if ($test_id > 0) {
    $sql = "SELECT id, nombre, created_at, usuario as email FROM usuarios WHERE id = $test_id";
} else {
    $sql = "SELECT id, nombre, created_at, usuario as email 
            FROM usuarios 
            WHERE rol = 'entrenador' 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ORDER BY created_at DESC";
}

$res = $conn->query($sql);

if (!$res || $res->num_rows === 0) {
    die(json_encode(["status" => "success", "message" => "No coaches to remind."]));
}

$reminders_sent = 0;
$details = [];

while ($coach = $res->fetch_assoc()) {
    $coach_id = $coach['id'];
    $nombre = explode(' ', trim($coach['nombre']))[0];

    // Reuse onboarding logic
    $pending_steps = [];
    
    // Check Step 1: Packs
    $res1 = $conn->query("SELECT id FROM packs WHERE entrenador_id = $coach_id AND activo = 1 LIMIT 1");
    if (!$res1 || $res1->num_rows === 0) $pending_steps[] = "crear tu primer pack de clases";

    // Check Step 2: Disponibilidad
    if (empty($pending_steps)) {
        $res2 = $conn->query("SELECT id FROM disponibilidad_profesor WHERE profesor_id = $coach_id AND activo = 1 LIMIT 1");
        if (!$res2 || $res2->num_rows === 0) $pending_steps[] = "configurar tus horarios de disponibilidad";
    }

    // Check Step 4: Alumnos (Skip Step 3 Profile for now as it's less critical for activation)
    if (empty($pending_steps)) {
        $res4 = $conn->query("SELECT id FROM entrenador_alumno WHERE entrenador_id = $coach_id LIMIT 1");
        $has_manual = ($res4 && $res4->num_rows > 0);
        $res4b = $conn->query("SELECT id FROM usuarios WHERE entrenador_creador_id = $coach_id AND rol = 'alumno' LIMIT 1");
        $has_created = ($res4b && $res4b->num_rows > 0);
        if (!$has_manual && !$has_created) $pending_steps[] = "añadir a tu primer alumno";
    }

    // If there's a pending step, send a reminder
    if (!empty($pending_steps)) {
        $next_step = $pending_steps[0];
        
        // Avoid spam: check if we sent a reminder in the last 24 hours
        $check_rem = $conn->query("SELECT id FROM notificaciones 
                                   WHERE user_id = $coach_id 
                                   AND tipo = 'coach_activation' 
                                   AND fecha_registro >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        
        if ($check_rem && $check_rem->num_rows === 0) {
            $titulo = "¡Hola $nombre! 🎾 ¿Necesitas ayuda?";
            $mensajes = [
                "Tu academia está casi lista. Solo te falta $next_step para empezar a gestionar tus clases.",
                "¡No te quedes atrás! Completa el paso de $next_step y profesionaliza tu academia hoy mismo.",
                "¿Sabías que los coaches que completan su perfil activan a sus alumnos 3x más rápido? Falta $next_step."
            ];
            $mensaje = $mensajes[array_rand($mensajes)];

            if (notifyUser($conn, $coach_id, $titulo, $mensaje, 'coach_activation')) {
                $reminders_sent++;
                $details[] = ["coach" => $coach['nombre'], "step" => $next_step];
            }
        }
    }
}

echo json_encode([
    "status" => "success",
    "reminders_sent" => $reminders_sent,
    "details" => $details
]);

$conn->close();
