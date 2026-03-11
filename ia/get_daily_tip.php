<?php
require_once "../db.php";

// Definir la API KEY (importada temporalmente o definida hardcoded para que coincida con la lógica de video)
$GEMINI_API_KEY = "AIzaSyDtZxXN0bb-bI2tvwb9I8R5_ppaA5OcqAE";

// 1. Verificar el caché local (si ya generamos un tip HOY, lo devolvemos rápido para no gastar API)
$hoy = date('Y-m-d');
$sqlCheck = "SELECT titulo, mensaje FROM tips_diarios_ia WHERE fecha = ? LIMIT 1";
$stmtCheck = $conn->prepare($sqlCheck);
$stmtCheck->bind_param("s", $hoy);
$stmtCheck->execute();
$resCheck = $stmtCheck->get_result()->fetch_assoc();

if ($resCheck) {
    echo json_encode(["status" => "success", "source" => "cache", "titulo" => $resCheck['titulo'], "mensaje" => $resCheck['mensaje']]);
    exit;
}

// 2. Si no hay caché, pedir a GEMINI AI
$prompt = "Actúa como un experto en Growth Hacking y Entrenador de Pádel Pro. Genera UN (solo uno) consejo técnico o táctico sobre pádel. Debe ser altamente accionable. Formato estricto: 'Titulo Corto (Gancho) | El texto del consejo'. El texto del consejo debe tener máximo 140 caracteres y hacer sentir al jugador 'FOMO' por aplicarlo hoy en la pista. No uses comillas, solo el Titulo Corto, una barra vertical '|', y luego el consejo.";

$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key=" . $GEMINI_API_KEY;

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

    // 3. Guardar en Base de Datos para todo el día de hoy
    $stmtInsert = $conn->prepare("INSERT INTO tips_diarios_ia (fecha, titulo, mensaje) VALUES (?, ?, ?)");
    if($stmtInsert) {
        $stmtInsert->bind_param("sss", $hoy, $titulo, $mensaje);
        $stmtInsert->execute();
        $stmtInsert->close();
    }

    echo json_encode(["status" => "success", "source" => "gemini", "titulo" => $titulo, "mensaje" => $mensaje]);
} else {
    // Fallback if AI fails
    $titulo = "⚡ Acción Rápida";
    $mensaje = "Flexiona más las rodillas en la volea. Esa simple acción evitará que la bola se levante y te pasen. ¡Pruébalo ahora!";
    echo json_encode(["status" => "success", "source" => "fallback", "titulo" => $titulo, "mensaje" => $mensaje]);
}
$conn->close();
