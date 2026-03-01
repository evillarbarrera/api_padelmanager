<?php
/**
 * Gemini Video Analysis Proxy
 * Integrates with Google Gemini 1.5 Flash via REST API
 * Optimized for larger files using Resumable Upload
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("HTTP/1.1 200 OK");
    exit;
}

header("Content-Type: application/json");

$GEMINI_API_KEY = "AIzaSyDtZxXN0bb-bI2tvwb9I8R5_ppaA5OcqAE";

require_once "../db.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Método no permitido"]);
    exit;
}

$video_url = $_POST['video_url'] ?? '';
$video_id = $_POST['video_id'] ?? null;

if (empty($video_url)) {
    echo json_encode(["error" => "video_url es obligatorio"]);
    exit;
}

// 0. Check if analysis already exists in DB to save costs/time
if ($video_id) {
    $check_sql = "SELECT ai_report FROM entrenamiento_videos WHERE id = ?";
    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param("i", $video_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    if ($row && !empty($row['ai_report'])) {
        echo json_encode([
            "success" => true,
            "analysis" => json_decode($row['ai_report'], true),
            "cached" => true
        ]);
        exit;
    }
}

try {
    // 1. Download the video to a temporary file
    $temp_video = tempnam(sys_get_temp_dir(), 'gemini_vid_');
    $ch = curl_init($video_url);
    $fp = fopen($temp_video, 'wb');
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120); // Longer for big files
    curl_exec($ch);
    curl_close($ch);
    fclose($fp);

    $file_size = filesize($temp_video);
    if ($file_size < 100) {
        throw new Exception("El video no se pudo descargar correctamente o es demasiado pequeño.");
    }

    // 2. Resumable Upload Protocol (More stable for > 5MB)
    // Step A: Initial session request
    $init_url = "https://generativelanguage.googleapis.com/upload/v1beta/files?key=" . $GEMINI_API_KEY;
    
    $file_metadata = [
        'file' => ['display_name' => 'padel_video_' . time()]
    ];
    $metadata_json = json_encode($file_metadata);

    $ch = curl_init($init_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-Goog-Upload-Protocol: resumable",
        "X-Goog-Upload-Command: start",
        "X-Goog-Upload-Header-Content-Length: " . $file_size,
        "X-Goog-Upload-Header-Content-Type: video/mp4",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $metadata_json);
    curl_setopt($ch, CURLOPT_HEADER, true); // We need headers to get upload URL
    
    $response = curl_exec($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $header_size);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Extract X-Goog-Upload-URL
    $upload_session_url = '';
    if (preg_match('/x-goog-upload-url: (.*)/i', $headers, $matches)) {
        $upload_session_url = trim($matches[1]);
    }

    if ($http_code !== 200 || empty($upload_session_url)) {
        throw new Exception("No se pudo iniciar la sesión de subida a Gemini: " . $response);
    }

    // Step B: Upload the file data
    $ch = curl_init($upload_session_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-Goog-Upload-Offset: 0",
        "X-Goog-Upload-Command: upload, finalize",
        "Content-Length: " . $file_size
    ]);
    
    // Read file in chunks to avoid memory issues
    $file_handle = fopen($temp_video, 'rb');
    curl_setopt($ch, CURLOPT_INFILE, $file_handle);
    curl_setopt($ch, CURLOPT_INFILESIZE, $file_size);
    curl_setopt($ch, CURLOPT_UPLOAD, true);
    
    $upload_response = curl_exec($ch);
    $upload_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($file_handle);

    $file_data = json_decode($upload_response, true);
    if ($upload_http_code !== 200 || !isset($file_data['file']['uri'])) {
        throw new Exception("Error al subir video a Gemini (fase datos): " . $upload_response);
    }

    $file_uri = $file_data['file']['uri'];
    $file_name = $file_data['file']['name'];

    // 3. Wait for processing (Polling state)
    $max_attempts = 30; // Increased for longer videos (up to 60s wait)
    $processed = false;
    for ($i = 0; $i < $max_attempts; $i++) {
        sleep(2);
        $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/" . $file_name . "?key=" . $GEMINI_API_KEY);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $status_res = curl_exec($ch);
        curl_close($ch);
        
        $status_data = json_decode($status_res, true);
        if (isset($status_data['state'])) {
            if ($status_data['state'] === 'ACTIVE') {
                $processed = true;
                break;
            } elseif ($status_data['state'] === 'FAILED') {
                throw new Exception("El video no pudo ser procesado por Gemini.");
            }
        }
    }

    if (!$processed) {
        throw new Exception("Tiempo de espera agotado procesando el video.");
    }

    // 4. Generate Content (The Analysis)
    $prompt = "Actúa como un video-analista técnico de padel profesional. 
    Analiza este video de entrenamiento y extrae la siguiente información técnica:
    1. Identifica el golpe principal (ej: Bandeja, Smash, Drive, Revés, Volea, etc).
    2. Calcula un Match Técnico Total (0 a 100) comparado con la técnica perfecta.
    3. Escribe un feedback técnico de máximo 30 palabras explicando lo que el jugador hizo bien y qué debe corregir.
    4. Evalúa 3 métricas críticas (preparación, impacto, terminación) de 0 a 10.
    5. Proporciona 2 tips prácticos y cortos para mejorar el golpe mostrado.

    IMPORTANTE: Responde ÚNICAMENTE con un objeto JSON válido con esta estructura exacta, sin texto adicional:
    {
        \"title\": \"Nombre del golpe\",
        \"score\": 85,
        \"feedback\": \"Texto del feedback...\",
        \"metrics\": [
            {\"label\": \"Preparación\", \"value\": 9},
            {\"label\": \"Impacto\", \"value\": 7},
            {\"label\": \"Terminación\", \"value\": 8}
        ],
        \"tips\": [\"Tip 1\", \"Tip 2\"]
    }";

    $gen_url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $GEMINI_API_KEY;

    $gen_payload = [
        "contents" => [
            [
                "parts" => [
                    ["text" => $prompt],
                    ["file_data" => ["mime_type" => "video/mp4", "file_uri" => $file_uri]]
                ]
            ]
        ],
        "generationConfig" => [
            "response_mime_type" => "application/json"
        ]
    ];

    $ch = curl_init($gen_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($gen_payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);

    $analysis_res = curl_exec($ch);
    curl_close($ch);

    $analysis_data = json_decode($analysis_res, true);
    
    $raw_text = $analysis_data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    
    if (empty($raw_text)) {
        error_log("Gemini Empty Response: " . $analysis_res);
        throw new Exception("Gemini no devolvió texto. Respuesta: " . substr($analysis_res, 0, 200));
    }

    // Attempt to extract JSON even if it's wrapped in markdown ```json ... ```
    if (preg_match('/\{.*\}/s', $raw_text, $json_matches)) {
        $final_json_text = $json_matches[0];
    } else {
        $final_json_text = $raw_text;
    }

    $final_analysis = json_decode($final_json_text, true);
    if (!$final_analysis) {
        throw new Exception("Error al decodificar JSON de Gemini: " . substr($final_json_text, 0, 100));
    }
    
    // Save to database
    if ($video_id) {
        // First, ensure the column exists (Lazy Migration)
        $conn->query("ALTER TABLE entrenamiento_videos ADD COLUMN IF NOT EXISTS ai_report TEXT AFTER comentario");
        
        $update_sql = "UPDATE entrenamiento_videos SET ai_report = ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("si", $final_json_text, $video_id);
        $update_stmt->execute();
    }

    if (isset($temp_video) && file_exists($temp_video)) unlink($temp_video);

    echo json_encode([
        "success" => true,
        "analysis" => $final_analysis
    ]);

} catch (Exception $e) {
    if (isset($temp_video) && file_exists($temp_video)) unlink($temp_video);
    error_log("IA Analysis Exception: " . $e->getMessage());
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
