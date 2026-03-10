<?php
require_once "../db.php";
require_once "../vendor/autoload.php"; // Asumiendo que usas un autoloader para FCM/firebase_auth si tienes backend activo, o simplemente la DB

// Lista de 20 Consejos Growth Hacking Padel (Max 140 chars)
$tips = [
    "1. ¿Tu volea se queda en la red? El error invisible que cometes con tu pie de apoyo es... Abre y descúbrelo 🛑",
    "2. El secreto de una bandeja profunda no está en la fuerza, sino en este ajuste de tu hombro... Mira cómo 👇",
    "3. La víbora perfecta existe. Solo debes rotar la muñeca *exactamente* en este milisegundo. Mira el truco 🐍",
    "4. Remate x3: aplica la 'técnica del arquero' para sumar 30% más de potencia sin dolor de codo. Paso a paso 🏹",
    "5. Bloquear balas en la red requiere menos de ti, no más. Conoce el concepto de la 'pala de cemento' aquí 🧱",
    "6. Domina el rulo a la reja salvaje. Apunta a este eslabón específico y despista al rival. Te enseñamos 🕸️",
    "7. Tu globo se queda corto por tu empuñadura. Modifica tu agarre 2 centímetros hacia abajo y domina la pista 🚀",
    "8. Nunca defiendas el smash rival pisando la línea de saque. Da un paso hacia este lugar mágico y gana... 🏃💨",
    "9. La bajada de pared letal no se pega fuerte, se raspa. Te enseñamos el ángulo exacto para que no bote 🧱💥",
    "10. ¿Restas mal los saques con efecto? Baja tu centro de gravedad 10cm y cambia el armado a esta altura. Lee 🛡️",
    "11. Salida de pared de revés: el error del 90% es quebrarla muy pronto. Fija tu muñeca de esta forma 🔙",
    "12. ¿Te hacen la nevera (hielo)? Rompe su estrategia con esta táctica de 2 pasos para recuperar tu bola 🧊🔥",
    "13. No choquen más palas. La regla de que el 'Drive manda al medio' tiene una excepción crítica. Gana el punto 🤝",
    "14. Pierdes puntos en 'tierra de nadie'. Detén tu avance y aplica la regla de 1 segundo de los Pros ⏳",
    "15. Cubre tu paralelo como un WPT. La regla del 'limpiaparabrisas' cerrará los huecos de tu pista. Toca aquí 🚘",
    "16. ¿Primer saque a la T o al cristal? Las matemáticas de los Pro dictan que tu 1er turno debe ir hacia... 📊",
    "17. Anticipa su contra-globo. Si tu rival pega la pala al cuerpo, sube a la red INMEDIATO. Mira el video 👀⬆️",
    "18. Jugar con viento requiere esconder el globo. Usa la 'chiquita' cruzada con este efecto y ganarás. 🌬️",
    "19. ¿Llegas fundido al 3er set? Tu desgaste es por correr en 'V'. Aprende la ruta de la 'L invertida' aquí 🔋",
    "20. Tu calentamiento actual frena tu explosividad. Los 3 estiramientos dinámicos de 30s que te faltan 🔥"
];

// Seleccionar Tip del Día basado en el día del año para rotarlos automáticamente
$dayOfYear = date('z');
$tipIndex = $dayOfYear % count($tips);
$selectedTip = $tips[$tipIndex];

// Sacar el título y mensaje (split by ":" o por el número)
$parts = explode(":", $selectedTip, 2);
if (count($parts) > 1) {
    $titulo = "Tip Pro: " . trim(preg_replace('/^[0-9.]+\s*/', '', $parts[0]));
    $mensaje = trim($parts[1]);
} else {
    $titulo = "🔥 El Tip de Mejora de Hoy";
    $mensaje = trim(preg_replace('/^[0-9.]+\s*/', '', $selectedTip));
}

// 1. Obtener todos los alumnos activos (jugadores)
$sql = "SELECT id FROM usuarios WHERE rol = 'jugador'";
$res = $conn->query($sql);

if ($res && $res->num_rows > 0) {
    // Preparar el statement masivo para enviar notificaciones in-app
    $stmtNotif = $conn->prepare("INSERT INTO notificaciones (user_id, titulo, mensaje, tipo, leida, created_at) VALUES (?, ?, ?, 'daily_tip', 0, NOW())");
    
    // Preparar variables fijas
    $tPush = $titulo;
    $mPush = $mensaje;

    $count = 0;
    while ($row = $res->fetch_assoc()) {
        $jugadorId = $row['id'];
        
        // Insert DB para que salga el Badge ROJO en la APP (campanita)
        $stmtNotif->bind_param("iss", $jugadorId, $tPush, $mPush);
        if ($stmtNotif->execute()) {
            $count++;
        }
        
    }
    
    $stmtNotif->close();
    
    echo json_encode(["status" => "success", "message" => "$count tips enviados a la DB.", "tip_enviado" => $mPush]);
} else {
    echo json_encode(["status" => "error", "message" => "No se encontraron jugadores activos"]);
}
$conn->close();
