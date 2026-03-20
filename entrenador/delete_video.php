<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

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
require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);
$video_id = $data['video_id'] ?? null;

// Log for debugging (remove in production if needed)
// error_log("Delete attempt: ID=$video_id, Raw=$rawInput");

if (!$video_id) {
    // Try to get from $_POST if json_decode failed or empty
    $video_id = $_POST['video_id'] ?? null;
}

if (!$video_id) {
    http_response_code(400);
    echo json_encode(["error" => "video_id es obligatorio", "received" => $rawInput]);
    exit;
}

// 1. Get file path to delete it from disk
$stmt = $conn->prepare("SELECT video_url FROM entrenamiento_videos WHERE id = ?");
$stmt->bind_param("i", $video_id);
$stmt->execute();
$result = $stmt->get_result();
$video = $result->fetch_assoc();

if ($video) {
    $url = $video['video_url'];
    // Extract filename from URL: https://api.padelmanager.cl/api_training/uploads/videos/vid_...
    $parts = explode('/', $url);
    $filename = end($parts);
    $filepath = '../uploads/videos/' . $filename;

    if (file_exists($filepath)) {
        unlink($filepath);
    }

    // 2. Delete from DB
    $stmtDel = $conn->prepare("DELETE FROM entrenamiento_videos WHERE id = ?");
    $stmtDel->bind_param("i", $video_id);
    
    if ($stmtDel->execute()) {
        echo json_encode(["success" => true, "message" => "Video eliminado correctamente"]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Error al eliminar de la base de datos: " . $conn->error]);
    }
} else {
    http_response_code(404);
    echo json_encode(["error" => "Video no encontrado"]);
}
?>
