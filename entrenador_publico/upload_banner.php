<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";
require_once "../auth/auth_helper.php";
$userId = validateToken();
if (!$userId) {
    sendUnauthorized("Token inválido");
}

$file = $_FILES['banner'] ?? $_FILES['archivo'] ?? $_FILES['file'] ?? null;
if (!$file && !empty($_FILES)) {
    $file = reset($_FILES);
}

if (!$file || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    $errCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    $errDesc = "No se subió archivo o hubo un error al cargar";
    if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
        $errDesc = "El archivo supera el tamaño máximo permitido por el servidor PHP.";
    } elseif ($errCode === UPLOAD_ERR_PARTIAL) {
        $errDesc = "El archivo se subió solo parcialmente.";
    } elseif ($errCode === UPLOAD_ERR_NO_FILE) {
        $errDesc = "No se seleccionó ningún archivo para subir.";
    }
    echo json_encode(["status" => "error", "message" => $errDesc, "code" => $errCode]);
    exit;
}

$upload_dir = '../uploads/entrenadores/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'webp', 'avif'];
if (!in_array($file_ext, $allowed)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Formato no permitido. Usa JPG, PNG o WEBP."]);
    exit;
}

$file_name = 'banner_' . $userId . '_' . time() . '.' . $file_ext;
$target_file = $upload_dir . $file_name;

if (move_uploaded_file($file['tmp_name'], $target_file)) {
    $full_url = 'https://api.padelmanager.cl/prd/uploads/entrenadores/' . $file_name;
    
    // Auto-save banner_url in DB if record exists
    $stmt = $conn->prepare("UPDATE entrenador_perfil_publico SET banner_url = ? WHERE entrenador_id = ?");
    if ($stmt) {
        $stmt->bind_param("si", $full_url, $userId);
        $stmt->execute();
    }

    echo json_encode([
        "status" => "success",
        "message" => "Banner subido exitosamente",
        "banner_url" => $full_url
    ]);
} else {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al guardar el archivo"]);
}
