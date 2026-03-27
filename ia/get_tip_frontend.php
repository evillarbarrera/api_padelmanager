<?php
header("Access-Control-Allow-Origin: *");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, X-Authorization, Content-Type");
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../auth/auth_helper.php";
$tokenUserId = validateToken();
if (!$tokenUserId) {
    sendUnauthorized();
}

header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

require_once "../db.php";

$hoy = date('Y-m-d');

// 1. Check if we have tips for today
$sqlCheck = "SELECT titulo, mensaje, posicion FROM tips_diarios_ia WHERE fecha = ? ORDER BY posicion ASC";
$stmtCheck = $conn->prepare($sqlCheck);
$stmtCheck->bind_param("s", $hoy);
$stmtCheck->execute();
$resCheck = $stmtCheck->get_result();

$tips = [];
while($row = $resCheck->fetch_assoc()) {
    $tips[] = $row;
}

// 2. If no tips for today, generate them on-the-fly
if (count($tips) === 0) {
    $GEMINI_API_KEY = "AIzaSyDtZxXN0bb-bI2tvwb9I8R5_ppaA5OcqAE";
    
    $dayOfWeek = date('l');
    $dayNum = date('j');
    $month = date('F');
    
    $prompt = "Actúa como un experto entrenador de Pádel profesional. Hoy es $dayOfWeek $dayNum de $month. 
Genera exactamente 2 consejos técnicos ÚNICOS y VARIADOS para hoy. 
Alterna entre estos temas cada día: volea, bandeja, víbora, saque, resto, posicionamiento, juego en pared, globo, smash, defensa, comunicación en pareja, estrategia de puntos.
Formato estricto (una línea por consejo):
Emoji Titulo Corto | Consejo práctico de 1-2 frases.
Solo devuelve las 2 líneas, nada más.";

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . $GEMINI_API_KEY;
    $data = ["contents" => [["parts" => [["text" => $prompt]]]]];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    curl_close($ch);

    $resAI = json_decode($response, true);

    if (isset($resAI['candidates'][0]['content']['parts'][0]['text'])) {
        $lines = explode("\n", trim($resAI['candidates'][0]['content']['parts'][0]['text']));
        $p = 1;
        foreach($lines as $l) {
            $l = trim($l);
            if (empty($l)) continue;
            $l = preg_replace('/^[\*\-\d\.]+\s*/', '', $l);
            if(strpos($l, '|') !== false) {
                $parts = explode("|", $l, 2);
                $tips[] = ["titulo" => trim($parts[0]), "mensaje" => trim($parts[1]), "posicion" => $p];
                $p++;
                if($p > 2) break;
            }
        }
    }

    // 3. Fallback: rotating pool based on day-of-year
    if (count($tips) < 2) {
        $pool = [
            ["titulo" => "⚡ Volea Cortada", "mensaje" => "Abre la cara de la pala al impactar para darle efecto cortado. La bola rebotará bajo y será difícil de devolver."],
            ["titulo" => "🧠 Lee la Jugada", "mensaje" => "Observa la posición corporal del rival antes de que golpee para anticipar si tirará paralelo o cruzado."],
            ["titulo" => "🎯 Bandeja Profunda", "mensaje" => "Apunta al fondo de la pista rival. Una bandeja corta es una invitación a que te ataquen."],
            ["titulo" => "💪 Posición de Espera", "mensaje" => "Mantén rodillas flexionadas y pala a la altura del pecho entre golpes. Reaccionarás más rápido."],
            ["titulo" => "🔥 Víbora con Muñeca", "mensaje" => "El secreto de la víbora está en el giro de muñeca en el último momento, no en todo el brazo."],
            ["titulo" => "🎾 Saque al Cuerpo", "mensaje" => "Apunta al cuerpo del restador. No tendrá ángulo cómodo para devolver y ganarás restos flojos."],
            ["titulo" => "🏃 Transición a Red", "mensaje" => "Después de un buen globo, avanza a la red. El globo te da tiempo para ganar posición ofensiva."],
            ["titulo" => "🛡️ Defensa en Pared", "mensaje" => "Deja que la bola rebote en el cristal y golpea hacia arriba con un globo alto. No ataques desde atrás."],
            ["titulo" => "💬 Comunica en Pareja", "mensaje" => "Antes de cada punto, define quién cubre el medio. Un 'tuya' o 'mía' evita errores no forzados."],
            ["titulo" => "🎯 Resto Cruzado", "mensaje" => "Devuelve el saque cruzado hacia los pies del sacador. Más margen y te permite tomar la red."],
            ["titulo" => "⚡ Smash con Dirección", "mensaje" => "Elige la dirección ANTES de saltar. No cambies en el aire. El smash se define con la mente."],
            ["titulo" => "🧠 Paciencia Gana Puntos", "mensaje" => "Construye el punto con 3-4 golpes seguros antes de buscar el winner. El pádel es paciencia."],
            ["titulo" => "🔄 Cambia de Ritmo", "mensaje" => "Alterna entre golpes rápidos y lentos. El cambio de ritmo desconcierta más que la potencia pura."],
            ["titulo" => "📐 Ángulos Cruzados", "mensaje" => "Un golpe al ángulo corto obliga al rival a moverse más y abre espacios en la pista."],
            ["titulo" => "🎾 Globo como Arma", "mensaje" => "Un buen globo no es defensivo, es una herramienta para sacar rivales de la red. Úsalo con intención."],
            ["titulo" => "💪 Grip Continental", "mensaje" => "Usa el grip continental para voleas y bandejas. Más control y no necesitas cambiar de agarre."],
            ["titulo" => "🏆 Primer Servicio In", "mensaje" => "Un primer saque adentro, aunque no sea potente, es más valioso que un cañonazo fuera."],
            ["titulo" => "🛡️ Espacio con el Cristal", "mensaje" => "Mantén distancia del cristal lateral. Si estás pegado, no tendrás espacio para golpear el rebote."]
        ];

        $dayOfYear = date('z');
        $idx1 = ($dayOfYear * 2) % count($pool);
        $idx2 = ($dayOfYear * 2 + 1) % count($pool);
        
        $tips = [
            array_merge($pool[$idx1], ["posicion" => 1]),
            array_merge($pool[$idx2], ["posicion" => 2])
        ];
    }

    // 4. Save to DB for cache
    @$conn->query("CREATE TABLE IF NOT EXISTS tips_diarios_ia (id INT AUTO_INCREMENT PRIMARY KEY, fecha DATE, titulo VARCHAR(255), mensaje TEXT, posicion TINYINT DEFAULT 1, UNIQUE KEY unique_fecha_pos (fecha, posicion))");
    
    foreach($tips as $t) {
        $stmt = $conn->prepare("INSERT INTO tips_diarios_ia (fecha, titulo, mensaje, posicion) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE titulo=VALUES(titulo), mensaje=VALUES(mensaje)");
        $tit = $t['titulo'];
        $men = $t['mensaje'];
        $pos = (int)$t['posicion'];
        $stmt->bind_param("sssi", $hoy, $tit, $men, $pos);
        $stmt->execute();
    }
}

echo json_encode([
    "status" => "success", 
    "titulo" => $tips[0]['titulo'],
    "mensaje" => $tips[0]['mensaje'],
    "tips" => $tips
]);
$conn->close();
?>
