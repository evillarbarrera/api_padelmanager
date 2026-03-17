<?php
require_once "../db.php";
require_once "../notifications/fcm_sender.php";

// 1. Obtener los Tips de IA de hoy
// Forzamos la URL para asegurar que se generen si no existen
$url_ia = "https://api.padelmanager.cl/ia/get_daily_tip.php";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url_ia);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response_ia = curl_exec($ch);
curl_close($ch);

$ia_data = json_decode($response_ia, true);
$tips_disponibles = [];

if ($ia_data && isset($ia_data['tips']) && is_array($ia_data['tips'])) {
    $tips_disponibles = $ia_data['tips'];
}

if (empty($tips_disponibles)) {
    die(json_encode(["status" => "error", "message" => "No se pudieron obtener o generar tips para hoy."]));
}

// 2. Determinar qué tip enviar basado en el parámetro 'pos'
$posicion_a_enviar = isset($_GET['pos']) ? (int)$_GET['pos'] : 1;
$tip_seleccionado = null;

foreach ($tips_disponibles as $t) {
    if ($t['posicion'] == $posicion_a_enviar) {
        $tip_seleccionado = $t;
        break;
    }
}

// Si no encuentra la posición pedida, mandamos el primero por defecto
if (!$tip_seleccionado) {
    $tip_seleccionado = $tips_disponibles[0];
}

$titulo = $tip_seleccionado['titulo'];
$mensaje = $tip_seleccionado['mensaje'];

// 3. Obtener todos los alumnos activos
$sql = "SELECT u.id FROM usuarios u WHERE u.rol = 'jugador'";
$res = $conn->query($sql);

if ($res && $res->num_rows > 0) {
    require_once "../notifications/notificaciones_helper.php";
    $count_notificaciones = 0;

    while ($row = $res->fetch_assoc()) {
        $jugadorId = $row['id'];
        // notifyUser ya gestiona si se debe enviar Push y guardar en DB
        if (notifyUser($conn, $jugadorId, $titulo, $mensaje, 'daily_tip')) {
            $count_notificaciones++;
        }
    }
    
    echo json_encode([
        "status" => "success", 
        "message" => "Se envió el consejo #$posicion_a_enviar a $count_notificaciones jugadores.",
        "detalle" => ["titulo" => $titulo, "mensaje" => $mensaje]
    ]);
} else {
    echo json_encode(["status" => "error", "message" => "No se encontraron jugadores activos"]);
}

$conn->close();
?>
