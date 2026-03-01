<?php
/**
 * Gemini Video Analysis Proxy - Final v6 (Professional Stream Mode)
 * Optimized for large videos (46s+) and memory safety.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 600);
ini_set('memory_limit', '512M');

// Use a simple known log path
$log_file = dirname(__FILE__) . "/gemini_debug.txt";

function logger($msg) {
    global $log_file;
    $timestamp = date("Y-m-d H:i:s");
    $formatted_msg = "[$timestamp] $msg\n";
    @file_put_contents($log_file, $formatted_msg, FILE_APPEND);
    error_log("GeminiProxy: $msg");
}

logger("--- SCRIPT START ---");
logger("Log file location: $log_file");

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

$video_url = $_POST['video_url'] ?? '';
$video_id = $_POST['video_id'] ?? null;
$uploaded_file = $_FILES['video'] ?? null;

logger("--- NEW REQUEST ---");
logger("Video ID: $video_id | URL: $video_url | Uploaded: " . ($uploaded_file ? "YES" : "NO"));

if (empty($video_url) && !$uploaded_file) {
    echo json_encode(["error" => "video_url or uploaded video is required"]);
    exit;
}

try {
    // 0. Cache check
    if ($video_id) {
        $stmt = $conn->prepare("SELECT ai_report FROM entrenamiento_videos WHERE id = ?");
        $stmt->bind_param("i", $video_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        
        if ($row && !empty($row['ai_report']) && $row['ai_report'] !== 'null') {
            logger("Serving cached report from DB.");
            echo json_encode(["success" => true, "analysis" => json_decode($row['ai_report'], true), "cached" => true]);
            exit;
        }
    }

    // 1. Get Video File (Upload or Download)
    $temp_video = tempnam(sys_get_temp_dir(), 'gemini_');
    
    if ($uploaded_file) {
        logger("Handling uploaded file: " . $uploaded_file['name']);
        if (!move_uploaded_file($uploaded_file['tmp_name'], $temp_video)) {
            throw new Exception("Error al mover el archivo subido.");
        }
        $file_size = filesize($temp_video);
    } else {
        logger("Downloading video from: $video_url");
        $ch = curl_init($video_url);
        $fp = fopen($temp_video, 'wb');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 240);
        curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($http_code !== 200) {
            logger("Download failed with HTTP code $http_code");
            throw new Exception("Error descargando video del origen ($http_code)");
        }
        $file_size = filesize($temp_video);
    }
    
    logger("Local file ready: $temp_video, Size: " . number_format($file_size / 1024 / 1024, 2) . " MB");

    // 2. Resumable Upload - Step 1: Initialize
    logger("Initializing Gemini upload session...");
    $init_url = "https://generativelanguage.googleapis.com/upload/v1beta/files?key=" . $GEMINI_API_KEY;
    $metadata = json_encode(['file' => ['display_name' => 'clip_'.time()]]);

    $ch = curl_init($init_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-Goog-Upload-Protocol: resumable",
        "X-Goog-Upload-Command: start",
        "X-Goog-Upload-Header-Content-Length: $file_size",
        "X-Goog-Upload-Header-Content-Type: video/mp4",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $metadata);
    $res = curl_exec($ch);
    $h_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $h = substr($res, 0, $h_size);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !preg_match('/x-goog-upload-url: (.*)/i', $h, $m)) {
        logger("Init upload failed ($code). Body: " . substr($res, $h_size));
        throw new Exception("Error Gemini Upload Session ($code)");
    }
    $up_url = trim($m[1]);
    logger("Upload session established. URL follows.");

    // 2. Resumable Upload - Step 2: Stream File
    logger("Streaming file to Gemini...");
    $ch = curl_init($up_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    $stream_fp = fopen($temp_video, 'rb');
    curl_setopt($ch, CURLOPT_INFILE, $stream_fp);
    curl_setopt($ch, CURLOPT_INFILESIZE, $file_size);
    curl_setopt($ch, CURLOPT_UPLOAD, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Goog-Upload-Offset: 0", "X-Goog-Upload-Command: upload, finalize"]);
    $up_res = curl_exec($ch);
    $up_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($stream_fp);

    if ($up_code !== 200) {
        logger("Stream failed ($up_code). Response: $up_res");
        throw new Exception("Error streaming video data ($up_code)");
    }
    
    $up_data = json_decode($up_res, true);
    $f_name = $up_data['file']['name'];
    $f_uri = $up_data['file']['uri'];
    logger("Upload finished. Gemini File: $f_name");

    // 3. Wait Processing (Active Polling)
    logger("Waiting for Gemini technical processing (polling)...");
    $processed = false;
    for ($i = 0; $i < 60; $i++) {
        sleep(2);
        $st_res = @file_get_contents("https://generativelanguage.googleapis.com/v1beta/$f_name?key=$GEMINI_API_KEY");
        $st = json_decode($st_res, true);
        $state = $st['state'] ?? 'UNKNOWN';
        logger("Polling attempt $i: State is $state");
        
        if ($state === 'ACTIVE') { $processed = true; break; }
        if ($state === 'FAILED') throw new Exception("Gemini processing failed on their end.");
    }
    if (!$processed) {
        logger("Processing timeout after 120s.");
        throw new Exception("Timeout waiting for Gemini processing.");
    }

    // 4. Content Generation
    logger("Triggering content generation prompt...");
    $prompt = "Actúa como analista PROFESIONAL de padel. Analiza este video y responde ESTRICTAMENTE en ESPAÑOL y en formato JSON.
    Toda la respuesta debe estar en ESPAÑOL de España/Latinoamérica.
    
    Estructura requerida:
    {
      \"title\": \"Título descriptivo del golpe analizado en ESPAÑOL\",
      \"score\": 85,
      \"feedback\": \"Resumen técnico detallado en ESPAÑOL de máximo 30 palabras.\",
      \"metrics\": [
        {\"label\": \"Punto de Impacto\", \"value\": 8},
        {\"label\": \"Terminación\", \"value\": 7},
        {\"label\": \"Posicionamiento\", \"value\": 9}
      ],
      \"tips\": [
        \"Primer consejo de mejora en ESPAÑOL\",
        \"Segundo consejo de mejora en ESPAÑOL\"
      ]
    }
    
    Asegúrate de que las métricas tengan nombres claros en ESPAÑOL (ej: 'Preparación', 'Fluidez', 'Impacto').";
    
    $payload = [
        "contents" => [
            ["parts" => [
                ["text" => $prompt], 
                ["file_data" => ["mime_type" => "video/mp4", "file_uri" => $f_uri]]
            ]]
        ], 
        "generationConfig" => [
            "response_mime_type" => "application/json"
        ]
    ];

    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key=$GEMINI_API_KEY");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $an_res = curl_exec($ch);
    $an_err = curl_error($ch);
    $an_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($an_code !== 200) {
        throw new Exception("Generation failed ($an_code): $an_res - cURL error: $an_err");
    }

    $an_data = json_decode($an_res, true);
    $raw = $an_data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    logger("Raw Gemini Output ($an_code): " . substr($raw, 0, 100) . "...");
    
    // Rigorous JSON extraction
    $start_pos = strpos($raw, '{');
    $end_pos = strrpos($raw, '}');
    
    if ($start_pos !== false && $end_pos !== false && $end_pos > $start_pos) {
        $final_j = substr($raw, $start_pos, $end_pos - $start_pos + 1);
    } else {
        $final_j = $raw;
    }

    $parsed_json = json_decode($final_j, true);
    
    if (!$parsed_json) {
        throw new Exception("Gemini regresó formato erróneo: " . substr($final_j, 0, 100));
    }
    
    if ($video_id && !empty($final_j) && $parsed_json) {
        $up = $conn->prepare("UPDATE entrenamiento_videos SET ai_report = ? WHERE id = ?");
        $up->bind_param("si", $final_j, $video_id);
        $up->execute();
        logger("Report saved to DB.");
    }

    if (file_exists($temp_video)) @unlink($temp_video);
    logger("SUCCESS. Analysis sent.");
    echo json_encode(["success" => true, "analysis" => $parsed_json]);

} catch (Exception $e) {
    if (isset($temp_video) && file_exists($temp_video)) @unlink($temp_video);
    logger("FATAL ERROR: " . $e->getMessage());
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
