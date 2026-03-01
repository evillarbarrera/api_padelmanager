<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$headers = getallheaders();
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

$expectedToken = 'Bearer ' . base64_encode("1|padel_academy");

if (empty($auth) || $auth !== $expectedToken) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

// Check if file was uploaded
if (!isset($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(["error" => "No file uploaded or upload error"]);
    exit;
}

$user_id = $_POST['user_id'] ?? 0;
if (!$user_id) {
    http_response_code(400);
    echo json_encode(["error" => "user_id is required"]);
    exit;
}

// 1. Move uploads to project root
$upload_dir = '../uploads/perfiles/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$file_ext = pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
$file_name = 'perfil_' . $user_id . '_' . time() . '.' . $file_ext;
$target_file = $upload_dir . $file_name;

if (move_uploaded_file($_FILES['foto']['tmp_name'], $target_file)) {
    // 2. Correct URL construction (assuming api_training is root)
    $foto_url = 'https://api.padelmanager.cl/uploads/perfiles/' . $file_name;
    
    $sql = "UPDATE usuarios SET foto_perfil = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $foto_url, $user_id);
    $stmt->execute();

    echo json_encode([
        "success" => true,
        "message" => "Photo uploaded successfully",
        "foto_url" => $foto_url
    ]);
} else {
    http_response_code(500);
    $error = error_get_last();
    echo json_encode(["error" => "Failed to move uploaded file", "detail" => $error]);
}
