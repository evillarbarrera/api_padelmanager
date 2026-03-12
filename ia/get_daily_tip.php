<?php
require_once "../db.php";

// Definir la API KEY
// Generar una clave nueva si la anterior se filtró, pero por ahora caemos en fallback o usamos gemini-1.5-flash
$GEMINI_API_KEY = "AIzaSyDtZxXN0bb-bI2tvwb9I8R5_ppaA5OcqAE";

// 1. Verificar el caché local
$hoy = date('Y-m-d');
$refresh = isset($_GET['refresh']) && $_GET['refresh'] == '1';

if (!$refresh) {
    $sqlCheck = "SELECT titulo, mensaje FROM tips_diarios_ia WHERE fecha = ? LIMIT 1";
    $stmtCheck = $conn->prepare($sqlCheck);
    $stmtCheck->bind_param("s", $hoy);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result()->fetch_assoc();

    if ($resCheck) {
        echo json_encode(["status" => "success", "source" => "cache", "titulo" => $resCheck['titulo'], "mensaje" => $resCheck['mensaje']]);
        exit;
    }
}

// If refreshing, delete today's tip first to avoid primary key/unique conflict if any
if ($refresh) {
    $stmtDel = $conn->prepare("DELETE FROM tips_diarios_ia WHERE fecha = ?");
    $stmtDel->bind_param("s", $hoy);
    $stmtDel->execute();
    $stmtDel->close();
}

// 2. Si no hay caché, pedir a GEMINI AI
$prompt = "Actúa como un experto en Growth Hacking y Entrenador de Pádel Pro. Genera UN (solo uno) consejo técnico o táctico sobre pádel. Debe ser altamente accionable. Formato estricto: 'Titulo Corto (Gancho) | El texto del consejo'. El texto del consejo debe tener máximo 140 caracteres y hacer sentir al jugador 'FOMO' por aplicarlo hoy en la pista. No uses comillas, solo el Titulo Corto, una barra vertical '|', y luego el consejo.";

$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $GEMINI_API_KEY;

$data = [
    "contents" => [
        [
            "parts" => [
                ["text" => $prompt]
            ]
        ]
    ]
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
$response = curl_exec($ch);
curl_close($ch);

$responseData = json_decode($response, true);
$aiText = "";

if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
    $aiText = trim($responseData['candidates'][0]['content']['parts'][0]['text']);
    
    // Parse
    $parts = explode("|", $aiText, 2);
    if(count($parts) == 2) {
        $titulo = trim($parts[0]);
        $mensaje = trim($parts[1]);
    } else {
        $titulo = "🔥 Tip Pro IA";
        $mensaje = $aiText;
    }
    $source = "gemini";
} else {
    // Fallback if AI fails (ej: Key invalida, filtrada, etc.)
    $tips_fallback = [
        ["⚡ Acción Diaria", "Flexiona más las rodillas en la volea. Evitarás que la bola se levante."],
        ["🔥 Posición de Espera", "Mantén la pala alta y delante del cuerpo. Ahorrarás segundos valiosos al responder."],
        ["💎 Tip Táctico", "Juega al medio para crear confusión entre tus rivales y evitar ángulos imposibles."],
        ["🎾 Mejora tu Globo", "Arma abajo y acompaña el golpe hacia arriba para superar a los rivales."],
        ["🏆 Servicio Inteligente", "Varía la velocidad y dirección de tu saque para no volverlo predecible."],
        ["🛡️ Defensa Pro", "Cuando te arrinconen en el cristal, usa un globo alto y profundo para ganar tiempo."],
        ["🏃‍♂️ Anticipación", "Mira siempre la pala del rival al golpear, te dará pistas de adónde irá la bola."]
    ];
    $random_tip = $tips_fallback[array_rand($tips_fallback)];
    
    $titulo = $random_tip[0];
    $mensaje = $random_tip[1];
    $source = "fallback";
}

// 3. Guardar en Base de Datos para TODO el día de hoy (sea de IA o Fallback) para que frontend lo lea bien
$stmtInsert = $conn->prepare("INSERT INTO tips_diarios_ia (fecha, titulo, mensaje) VALUES (?, ?, ?)");
if($stmtInsert) {
    $stmtInsert->bind_param("sss", $hoy, $titulo, $mensaje);
    $stmtInsert->execute();
    $stmtInsert->close();
}

echo json_encode(["status" => "success", "source" => $source, "titulo" => $titulo, "mensaje" => $mensaje]);
$conn->close();
