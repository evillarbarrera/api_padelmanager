<?php
require_once "../db.php";
require_once "../notifications/fcm_sender.php";

// 1. Obtener o generar el Tip de IA de hoy usando get_daily_tip.php
// Usamos cURL al propio servidor para ejecutar la logica de la IA
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
$host = $_SERVER['HTTP_HOST'] ?? 'api.padelmanager.cl';
$url_ia = "$protocol://$host/ia/get_daily_tip.php";

// Si el host es localhost o no resuelve bien por cURL, vamos directo a BD.
// Como cron se ejecuta por CLI, $_SERVER['HTTP_HOST'] estara vacio, asique forzamos la URL externa
$url_ia = "https://api.padelmanager.cl/ia/get_daily_tip.php";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url_ia);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// Deshabilitar verify host temporalmente en caso de que cron moleste
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response_ia = curl_exec($ch);
curl_close($ch);

$ia_data = json_decode($response_ia, true);

if ($ia_data && isset($ia_data['titulo'])) {
    $titulo = $ia_data['titulo'];
    $mensaje = $ia_data['mensaje'];
} else {
    // Fallback si falla la IA o la conexion
    $titulo = "🔥 El Tip de Mejora de Hoy";
    $mensaje = "Flexiona más las rodillas en la volea. Esa simple acción evitará que la bola se levante y te pasen. ¡Pruébalo ahora!";
}

// 2. Obtener todos los alumnos activos y sus tokens FCM
$sql = "SELECT u.id, f.token 
        FROM usuarios u 
        LEFT JOIN fcm_tokens f ON u.id = f.user_id 
        WHERE u.rol = 'jugador'";
$res = $conn->query($sql);

if ($res && $res->num_rows > 0) {
    require_once "../notifications/notificaciones_helper.php";

    $countDB = 0;
    while ($row = $res->fetch_assoc()) {
        $jugadorId = $row['id'];
        if (notifyUser($conn, $jugadorId, $titulo, $mensaje, 'daily_tip')) {
            $countDB++;
        }
    }
    
    echo json_encode([
        "status" => "success", 
        "message" => "$countDB tips enviados correctamente a BD y Push (FCM).", 
        "tip_enviado" => $mensaje
    ]);
} else {
    echo json_encode(["status" => "error", "message" => "No se encontraron jugadores activos"]);
}
$conn->close();
?>
