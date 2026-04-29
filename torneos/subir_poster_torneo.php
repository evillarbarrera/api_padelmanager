<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

if (!isset($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(["error" => "No file uploaded or upload error"]);
    exit;
}

$upload_dir = '../uploads/torneos/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$file_ext = pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
$file_name = 'torneo_' . time() . '_' . rand(1000, 9999) . '.' . $file_ext;
$target_file = $upload_dir . $file_name;

if (move_uploaded_file($_FILES['foto']['tmp_name'], $target_file)) {
    $foto_url = 'https://api.padelmanager.cl/prd/uploads/torneos/' . $file_name;
    
    echo json_encode([
        "success" => true,
        "message" => "Poster uploaded successfully",
        "foto_url" => $foto_url
    ]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Failed to move uploaded file"]);
}
?>
