<?php
require_once "../db.php";

// Evitar errores 500 desactivando reporte de errores fatales en indices
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 0);
set_time_limit(90);

$GEMINI_API_KEY = "AIzaSyDtZxXN0bb-bI2tvwb9I8R5_ppaA5OcqAE";
$hoy = date('Y-m-d');
$refresh = isset($_GET['refresh']) && $_GET['refresh'] == '1';

// 1. MIGRACION SEGURA (Sin usar clausulas que fallan en versiones viejas)
// Añadir posicion si no existe
$resCol = $conn->query("SHOW COLUMNS FROM tips_diarios_ia LIKE 'posicion'");
if ($resCol && $resCol->num_rows == 0) {
    $conn->query("ALTER TABLE tips_diarios_ia ADD posicion TINYINT DEFAULT 1");
}

// Intentar quitar el index viejo y poner el nuevo (usamos @ para ignorar si ya estan hechos)
@$conn->query("ALTER TABLE tips_diarios_ia DROP INDEX unique_fecha");
@$conn->query("ALTER TABLE tips_diarios_ia ADD UNIQUE unique_fecha_pos (fecha, posicion)");

// 2. LOGICA DE CACHE
if (!$refresh) {
    $sql = "SELECT titulo, mensaje, posicion FROM tips_diarios_ia WHERE fecha = '$hoy' ORDER BY posicion ASC";
    $res = $conn->query($sql);
    if ($res && $res->num_rows >= 2) {
        $tips = [];
        while($row = $res->fetch_assoc()) { $tips[] = $row; }
        header('Content-Type: application/json');
        echo json_encode(["status" => "success", "source" => "cache", "tips" => $tips]);
        exit;
    }
}

if ($refresh) {
    $conn->query("DELETE FROM tips_diarios_ia WHERE fecha = '$hoy'");
}

// 3. PEDIR A GEMINI
$prompt = "Actúa como experto en Pádel. Genera DOS (2) consejos técnicos cortos. Formato: Titulo | Consejo. Uno por linea.";
$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $GEMINI_API_KEY;
$data = ["contents" => [["parts" => [["text" => $prompt]]]]];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
$response = curl_exec($ch);
curl_close($ch);

$resAI = json_decode($response, true);
$tips = [];

if (isset($resAI['candidates'][0]['content']['parts'][0]['text'])) {
    $lines = explode("\n", trim($resAI['candidates'][0]['content']['parts'][0]['text']));
    $p = 1;
    foreach($lines as $l) {
        if(strpos($l, '|') !== false) {
            $parts = explode("|", $l, 2);
            $tips[] = ["titulo" => trim($parts[0]), "mensaje" => trim($parts[1]), "posicion" => $p];
            $p++;
            if($p > 2) break;
        }
    }
}

// FALLBACK si la IA no responde bien
if (count($tips) < 2) {
    $tips = [
        ["titulo" => "⚡ Volea Pro", "mensaje" => "Flexiona rodillas al impactar la bola.", "posicion" => 1],
        ["titulo" => "🎾 Saque", "mensaje" => "Varía la profundidad para incomodar al rival.", "posicion" => 2]
    ];
}

// 4. GUARDAR
foreach($tips as $t) {
    $tit = $conn->real_escape_string($t['titulo']);
    $men = $conn->real_escape_string($t['mensaje']);
    $pos = (int)$t['posicion'];
    $conn->query("INSERT INTO tips_diarios_ia (fecha, titulo, mensaje, posicion) VALUES ('$hoy', '$tit', '$men', $pos) ON DUPLICATE KEY UPDATE titulo='$tit', mensaje='$men'");
}

header('Content-Type: application/json');
echo json_encode(["status" => "success", "tips" => $tips]);
$conn->close();
?>
