<?php
require_once "../db.php";
require_once "../notifications/fcm_sender.php";

// 1. Obtener o generar los Tips de IA de hoy directamente (Evitamos loopback cURL)
function getTipsDirectly($conn) {
    $hoy = date('Y-m-d');
    
    // Primero buscar en caché
    $sql = "SELECT titulo, mensaje, posicion FROM tips_diarios_ia WHERE fecha = '$hoy' ORDER BY posicion ASC";
    $res = $conn->query($sql);
    if ($res && $res->num_rows >= 1) {
        $tips = [];
        while($row = $res->fetch_assoc()) { $tips[] = $row; }
        return $tips;
    }

    // Si no hay hoy, generar Fallbacks de emergencia para que el sistema nunca falle
    $fallbacks = [
        ["titulo" => "⚡ Volea Pro", "mensaje" => "Flexiona ligeramente las rodillas al impactar la bola para ganar control.", "posicion" => 1],
        ["titulo" => "🎾 Saque Estratégico", "mensaje" => "Varía la dirección y profundidad de tu saque para mantener al rival incómodo.", "posicion" => 2]
    ];

    // Intentar guardarlos para hoy
    foreach($fallbacks as $t) {
        $tit = $conn->real_escape_string($t['titulo']);
        $men = $conn->real_escape_string($t['mensaje']);
        $pos = (int)$t['posicion'];
        $conn->query("INSERT IGNORE INTO tips_diarios_ia (fecha, titulo, mensaje, posicion) VALUES ('$hoy', '$tit', '$men', $pos)");
    }
    
    return $fallbacks;
}

$tips_disponibles = getTipsDirectly($conn);

if (empty($tips_disponibles)) {
    die(json_encode(["status" => "error", "message" => "Fallo crítico: No se pudieron obtener ni generar tips."]));
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

if (!$tip_seleccionado) {
    $tip_seleccionado = $tips_disponibles[0];
}

$titulo_base = $tip_seleccionado['titulo'];
$cuerpo_ia = $tip_seleccionado['mensaje'];

// 3. Obtener todos los alumnos activos (Incluimos nombre para personalizar)
$sql = "SELECT u.id, u.nombre FROM usuarios u WHERE u.rol = 'jugador'";
$res = $conn->query($sql);

if ($res && $res->num_rows > 0) {
    require_once "../notifications/notificaciones_helper.php";
    $count_notificaciones = 0;

    while ($row = $res->fetch_assoc()) {
        $jugadorId = $row['id'];
        $primerNombre = explode(' ', trim($row['nombre']))[0];

        // Personalización Dinámica
        if ($posicion_a_enviar == 1) {
            $saludos = [
                "¡Hola $primerNombre! Buen día. Tu Coach IA reportándose. 🎾",
                "¡Buen día $primerNombre! Aquí tienes tu primer tip de hoy para mejorar en la cancha:",
                "Hola $primerNombre, ¿listo para entrenar? Mira este consejo que tengo para ti:"
            ];
            $saludo = $saludos[array_rand($saludos)];
            $mensaje = "$saludo $cuerpo_ia";
        } else {
            $saludos = [
                "¡Hola de nuevo $primerNombre! Si vas a jugar hoy, no olvides esto:",
                "¿Cómo va el día $primerNombre? Aquí te dejo otro consejo clave:",
                "¡Hola $primerNombre! Te traigo un último tip para pulir tu técnica hoy:"
            ];
            $saludo = $saludos[array_rand($saludos)];
            $mensaje = "$saludo $cuerpo_ia";
        }

        if (notifyUser($conn, $jugadorId, "Consejo PadelManager: $titulo_base", $mensaje, 'daily_tip')) {
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
