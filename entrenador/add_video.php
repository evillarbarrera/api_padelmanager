<?php
// Disable showing errors as HTML to avoid breaking JSON
error_reporting(0);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Custom error handler to return JSON instead of PHP warnings
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "PHP Error: $errstr in $errfile on line $errline"]);
    exit;
});

try {
    if (!function_exists('getallheaders')) {
        function getallheaders() {
            $headers = [];
            foreach ($_SERVER as $name => $value) {
                if (substr($name, 0, 5) == 'HTTP_') {
                    $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
                }
            }
            return $headers;
        }
    }

    require_once "../db.php";

    // Auth
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($auth !== 'Bearer ' . base64_encode("1|padel_academy")) {
        http_response_code(401);
        echo json_encode(["error" => "Unauthorized"]);
        exit;
    }

    // 1. Detect if POST was exceeded (usually when file is too big)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && $_SERVER['CONTENT_LENGTH'] > 0) {
        $max_size = ini_get('post_max_size');
        http_response_code(413);
        echo json_encode(["error" => "El video es demasiado grande para el servidor. El límite actual es $max_size. Por favor sube un video más corto o comprimido."]);
        exit;
    }

    $jugador_id = $_POST['jugador_id'] ?? null;
    $entrenador_id = $_POST['entrenador_id'] ?? null;
    $tipo = $_POST['tipo'] ?? 'clase';
    $categoria = $_POST['categoria'] ?? 'General';
    $titulo = $_POST['titulo'] ?? '';
    $comentario = $_POST['comentario'] ?? '';

    // Robust check
    $has_video = isset($_FILES['video']) && $_FILES['video']['error'] !== UPLOAD_ERR_NO_FILE;

    if (!$jugador_id || $jugador_id == "null" || $jugador_id == "0" || !$has_video || ($tipo === 'clase' && !$entrenador_id)) {
        http_response_code(400);
        $missing = [];
        if (!$jugador_id || $jugador_id == "null" || $jugador_id == "0") $missing[] = "jugador_id";
        if (!$has_video) $missing[] = "archivo de video";
        if ($tipo === 'clase' && !$entrenador_id) $missing[] = "entrenador_id";
        
        echo json_encode(["error" => "Datos incompletos: " . implode(", ", $missing)]);
        exit;
    }

    if ($_FILES['video']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        $errors = [
            UPLOAD_ERR_INI_SIZE   => "El video excede el tamaño permitido por php.ini",
            UPLOAD_ERR_FORM_SIZE  => "El video excede el tamaño permitido por el formulario",
            UPLOAD_ERR_PARTIAL    => "El video se subió solo parcialmente",
            UPLOAD_ERR_NO_FILE    => "No se subió ningún archivo",
            UPLOAD_ERR_NO_TMP_DIR => "Falta la carpeta temporal en el servidor",
            UPLOAD_ERR_CANT_WRITE => "Error al escribir el archivo",
            UPLOAD_ERR_EXTENSION  => "Una extensión de PHP detuvo la subida",
        ];
        $errMsg = $errors[$_FILES['video']['error']] ?? "Error desconocido en la subida (" . $_FILES['video']['error'] . ")";
        echo json_encode(["error" => $errMsg]);
        exit;
    }

    $video = $_FILES['video'];
    $filename_orig = $video['name'];
    $ext = strtolower(pathinfo($filename_orig, PATHINFO_EXTENSION));
    
    // Some browsers send .mov or .qt and they are accepted
    $allowed = ['mp4', 'mov', 'avi', 'wmv', 'qt', 'quicktime'];
    if (!in_array($ext, $allowed) && $ext !== '') {
        // We log the extension but try to allow common ones
    }

    $filename = uniqid('vid_') . '.' . ($ext ?: 'mp4');
    $baseDir = dirname(dirname(__FILE__));
    $uploadDir = $baseDir . '/uploads/videos/';

    if (!file_exists($uploadDir)) {
        if (!mkdir($uploadDir, 0777, true)) {
            throw new Exception("No se pudo crear el directorio de subida: $uploadDir");
        }
    }
    $uploadPath = $uploadDir . $filename;

    if (move_uploaded_file($video['tmp_name'], $uploadPath)) {
        chmod($uploadPath, 0644);

        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
        $basePath = dirname($scriptDir);
        if ($basePath === DIRECTORY_SEPARATOR || $basePath === '\\') $basePath = '';
        
        $videoUrl = $protocol . "://" . $host . $basePath . "/uploads/videos/" . $filename;
        
        $stmt = $conn->prepare("INSERT INTO entrenamiento_videos (tipo, categoria, jugador_id, entrenador_id, video_url, titulo, comentario) VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        if (!$stmt) {
            throw new Exception("Error al preparar consulta (Asegúrate de haber ejecutado la migración): " . $conn->error);
        }

        $e_id = ($entrenador_id && $entrenador_id != "null") ? intval($entrenador_id) : null;
        $j_id = intval($jugador_id);
        $stmt->bind_param("ssiisss", $tipo, $categoria, $j_id, $e_id, $videoUrl, $titulo, $comentario);
        
        if ($stmt->execute()) {
            echo json_encode(["success" => true, "message" => "Video subido correctamente", "video_url" => $videoUrl]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "error" => "Error al guardar en BD: " . $conn->error]);
        }
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "error" => "Error al mover el archivo subido al destino final"]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "error" => "Error interno: " . $e->getMessage()]);
}
?>
