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

    // SI NO HAY TIPS, GENERARLOS (Lógica idéntica a get_tip_frontend.php)
    $GEMINI_API_KEY = "AIzaSyDtZxXN0bb-bI2tvwb9I8R5_ppaA5OcqAE";
    $seed = rand(1, 1000);
    $dayOfWeek = date('l'); $dayNum = date('j'); $month = date('F');
    $prompt = "Actúa como un experto entrenador de Pádel profesional (WPT style). Hoy es $dayOfWeek $dayNum de $month.
Genera exactamente 2 consejos técnicos de pádel TOTALMENTE ÚNICOS, INNOVADORES y PRÁCTICOS para hoy. 
Semilla de aleatoriedad: $seed.
Formato estricto (una línea por consejo):
Emoji Titulo Corto | Consejo técnico de 1-2 frases máximo.
Solo devuelve las 2 líneas.";

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $GEMINI_API_KEY;
    $data = ["contents" => [["parts" => [["text" => $prompt]]]]];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $tips = [];
    $resAI = json_decode($response, true);
    if ($httpCode === 200 && isset($resAI['candidates'][0]['content']['parts'][0]['text'])) {
        $lines = explode("\n", trim($resAI['candidates'][0]['content']['parts'][0]['text']));
        $p = 1;
        foreach($lines as $l) {
            $l = trim($l); if (empty($l)) continue;
            $l = preg_replace('/^[\*\-\d\.]+\s*/', '', $l);
            if(strpos($l, '|') !== false) {
                $parts = explode("|", $l, 2);
                $tips[] = ["titulo" => trim($parts[0]), "mensaje" => trim($parts[1]), "posicion" => $p];
                $p++; if($p > 2) break;
            }
        }
    }

    // Fallback Emergency pool
    if (count($tips) < 1) {
        $pool = [
            ["titulo" => "⚡ Volea de Bloqueo", "mensaje" => "No hagas backswing en la red. Deja que la potencia del rival rebote en tu pala firme."],
            ["titulo" => "🎾 Chiquita a los pies", "mensaje" => "Usa la chiquita cuando el rival esté pegado a la red para obligarlos a jugar bajo."],
            ["titulo" => "📐 Bandeja a la reja", "mensaje" => "Busca la reja lateral en tu bandeja para generar rebotes impredecibles."],
            ["titulo" => "🛡️ El Globo de Rescate", "mensaje" => "Un globo alto y profundo es tu mejor herramienta para recuperar la red."]
        ];
        shuffle($pool);
        $tips = [array_merge($pool[0], ["posicion" => 1]), array_merge($pool[1], ["posicion" => 2])];
    }

    // Guardar para hoy
    foreach($tips as $t) {
        $tit = $conn->real_escape_string($t['titulo']);
        $men = $conn->real_escape_string($t['mensaje']);
        $pos = (int)$t['posicion'];
        $conn->query("INSERT IGNORE INTO tips_diarios_ia (fecha, titulo, mensaje, posicion) VALUES ('$hoy', '$tit', '$men', $pos)");
    }
    
    return $tips;
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
