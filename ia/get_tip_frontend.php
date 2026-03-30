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

// Logging function
function tip_logger($msg) {
    $log = __DIR__ . "/tips_debug.log";
    $ts = date("Y-m-d H:i:s");
    @file_put_contents($log, "[$ts] $msg\n", FILE_APPEND);
}

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
    tip_logger("No tips found for $hoy. Generating via AI...");
    $GEMINI_API_KEY = "AIzaSyDtZxXN0bb-bI2tvwb9I8R5_ppaA5OcqAE";
    
    $dayOfWeek = date('l');
    $dayNum = date('j');
    $month = date('F');
    $seed = rand(1, 1000); // Add randomness to avoid identical responses
    
    $prompt = "Actúa como un experto entrenador de Pádel profesional (WPT style). Hoy es $dayOfWeek $dayNum de $month.
Genera exactamente 2 consejos técnicos de pádel TOTALMENTE ÚNICOS, INNOVADORES y PRÁCTICOS para hoy. 
No repitas consejos básicos. Busca matices avanzados sobre: volea de bloqueo, chiquita al hueco, bandeja a la reja, táctica de 'nevera', o psicología en el tercer set.
Semilla de aleatoriedad: $seed.

Formato estricto (una línea por consejo):
Emoji Titulo Corto | Consejo técnico de 1-2 frases máximo.
Solo devuelve las 2 líneas, nada de texto extra.";

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

    $resAI = json_decode($response, true);

    if ($httpCode === 200 && isset($resAI['candidates'][0]['content']['parts'][0]['text'])) {
        $rawText = trim($resAI['candidates'][0]['content']['parts'][0]['text']);
        tip_logger("AI Success. Raw response: " . str_replace("\n", " ", $rawText));
        
        $lines = explode("\n", $rawText);
        $p = 1;
        foreach($lines as $l) {
            $l = trim($l);
            if (empty($l)) continue;
            // Clean markdown
            $l = preg_replace('/^[\*\-\d\.]+\s*/', '', $l);
            if(strpos($l, '|') !== false) {
                $parts = explode("|", $l, 2);
                $tips[] = ["titulo" => trim($parts[0]), "mensaje" => trim($parts[1]), "posicion" => $p];
                $p++;
                if($p > 2) break;
            }
        }
    } else {
        tip_logger("AI Request Failed (HTTP $httpCode). Error: " . ($response ?: "No response"));
    }

    // 3. Fallback: extensive pool with day-based shuffling
    if (count($tips) < 1) {
        tip_logger("Falling back to internal pool.");
        $pool = [
            ["titulo" => "⚡ Volea de Bloqueo", "mensaje" => "No hagas backswing en la red. Deja que la potencia del rival rebote en tu pala firme."],
            ["titulo" => "🎾 Chiquita a los pies", "mensaje" => "Usa la chiquita cuando el rival esté pegado a la red para obligarlo a volear por debajo del nivel de la cinta."],
            ["titulo" => "📐 Bandeja a la reja", "mensaje" => "Busca la reja lateral en tu bandeja para generar rebotes impredecibles que saquen al rival de pista."],
            ["titulo" => "🧠 La Táctica de la Nevera", "mensaje" => "Si un rival es muy superior, juega el 80% de las bolas al otro. Mantener al 'bueno' frío es clave emocional."],
            ["titulo" => "💪 Terminación del Smash", "mensaje" => "Apunta al hombro contrario en el final del golpe para maximizar el efecto top-spin y que la bola suba más."],
            ["titulo" => "🛡️ Salida de Pared Baja", "mensaje" => "Con bola baja, prioriza el globo. No intentes ganar el punto desde el fondo si no tienes altura de impacto."],
            ["titulo" => "🔄 Rotación de Hombros", "mensaje" => "El secreto de la potencia no es el brazo, es la rotación del tronco. Prepara el golpe girando los hombros completamente."],
            ["titulo" => "💬 Comunicación Activa", "mensaje" => "Informa a tu pareja la posición del rival antes de que golpee (ej: 'Uno arriba', 'Están atrás')."],
            ["titulo" => "⏳ Manejo de Tiempos", "mensaje" => "Camina más lento entre puntos si vas perdiendo. Rompe el ritmo del rival y recupera el foco mental."],
            ["titulo" => "🎯 Saque con Rebote Lateral", "mensaje" => "Busca que el saque bote cerca de la línea y muera en el cristal lateral. Obliga al resto a ser defensivo."],
            ["titulo" => "👣 Pasos de Ajuste", "mensaje" => "Usa pasos cortos y rápidos antes del impacto. La posición de pies determina el 70% del éxito del golpe."],
            ["titulo" => "🔥 Víbora Agresiva", "mensaje" => "Impacta la bola a las 2 en punto (si eres diestro) para lograr ese efecto lateral que no levanta del cristal."],
            ["titulo" => "🛡️ El Globo de Rescate", "mensaje" => "Un globo alto y profundo te permite respirar y recuperar la red. Es el golpe más importante del pádel."],
            ["titulo" => "⚡ Resto Anticipado", "mensaje" => "Da un pequeño salto de pre-activación justo cuando el sacador impacta la bola para mejorar tu reacción."],
            ["titulo" => "🏆 Mentalidad de Ganador", "mensaje" => "Celebra los errores del rival tanto como tus aciertos (para ti mismo). El pádel es un deporte de errores."],
            ["titulo" => "📐 Posicionamiento en V", "mensaje" => "Tú y tu pareja deben moverse como si estuvieran unidos por una cuerda. Si uno sube, el otro acompaña."],
            ["titulo" => "🎾 Impacto Adelantado", "mensaje" => "Trata de golpear siempre la bola por delante de tu cuerpo. Te dará mucho más control y dirección."],
            ["titulo" => "💪 El Agarre Correcto", "mensaje" => "No aprietes la pala con fuerza todo el tiempo. Solo tensa la mano en el momento exacto del impacto."]
        ];

        // Shuffle pool based on date to ensure variety if AI fails multiple days
        $daySeed = (int)date('z') + (int)date('Y');
        srand($daySeed);
        shuffle($pool);
        
        $tips = [
            array_merge($pool[0], ["posicion" => 1]),
            array_merge($pool[1], ["posicion" => 2])
        ];
    }

    // 4. Save to DB for cache
    @$conn->query("CREATE TABLE IF NOT EXISTS tips_diarios_ia (id INT AUTO_INCREMENT PRIMARY KEY, fecha DATE, titulo VARCHAR(255), mensaje TEXT, posicion TINYINT DEFAULT 1, UNIQUE KEY unique_fecha_pos (fecha, posicion))");
    
    foreach($tips as $t) {
        $stmt = $conn->prepare("INSERT INTO tips_diarios_ia (fecha, titulo, mensaje, posicion) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE titulo=VALUES(titulo), mensaje=VALUES(mensaje)");
        $tit = $t['titulo'] ?? '';
        $men = $t['mensaje'] ?? '';
        $pos = (int)($t['posicion'] ?? 1);
        $stmt->bind_param("sssi", $hoy, $tit, $men, $pos);
        if (!$stmt->execute()) {
            tip_logger("DB Insert Error: " . $stmt->error);
        }
    }
}

echo json_encode([
    "status" => "success", 
    "titulo" => $tips[0]['titulo'] ?? '',
    "mensaje" => $tips[0]['mensaje'] ?? '',
    "tips" => $tips
]);
$conn->close();
?>
?>
