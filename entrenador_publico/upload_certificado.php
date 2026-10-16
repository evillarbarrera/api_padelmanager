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
require_once "init_tables.php";
ensureCoachPublicProfileTables($conn);

$userId = validateToken();
if (!$userId) {
    sendUnauthorized("Token inválido");
}

$file = $_FILES['certificado'] ?? $_FILES['archivo'] ?? $_FILES['file'] ?? null;
if (!$file && !empty($_FILES)) {
    $file = reset($_FILES);
}

if (!$file || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    $errCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    $errDesc = "No se subió archivo o hubo un error al cargar.";
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

$titulo = trim($_POST['titulo'] ?? '');
if (empty($titulo)) {
    $titulo = pathinfo($file['name'], PATHINFO_FILENAME);
}
$institucion = trim($_POST['institucion'] ?? '');

$upload_dir = '../uploads/entrenadores/certificados/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed_images = ['jpg', 'jpeg', 'png', 'webp'];
$allowed_docs = ['pdf'];
$allowed = array_merge($allowed_images, $allowed_docs);

if (!in_array($file_ext, $allowed)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Formato no permitido. Usa JPG, PNG, WEBP o PDF."]);
    exit;
}

// Max 10MB
if ($file['size'] > 10 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "El archivo no debe superar los 10MB."]);
    exit;
}

$tipo_archivo = in_array($file_ext, $allowed_docs) ? 'pdf' : 'image';
$unique_suffix = time() . '_' . mt_rand(1000, 9999);
$file_name = 'cert_' . $userId . '_' . $unique_suffix . '.' . $file_ext;
$target_file = $upload_dir . $file_name;

if (move_uploaded_file($file['tmp_name'], $target_file)) {
    $full_url = 'https://api.padelmanager.cl/prd/uploads/entrenadores/certificados/' . $file_name;

    $stmt = $conn->prepare("INSERT INTO entrenador_certificados (entrenador_id, titulo, institucion, archivo_url, tipo_archivo, activo) VALUES (?, ?, ?, ?, ?, 1)");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error al registrar en base de datos: " . $conn->error]);
        exit;
    }
    $stmt->bind_param("issss", $userId, $titulo, $institucion, $full_url, $tipo_archivo);
    $stmt->execute();
    $certId = $stmt->insert_id;
    $stmt->close();

    echo json_encode([
        "status" => "success",
        "message" => "Certificado subido exitosamente",
        "certificado" => [
            "id" => $certId,
            "entrenador_id" => $userId,
            "titulo" => $titulo,
            "institucion" => $institucion,
            "archivo_url" => $full_url,
            "tipo_archivo" => $tipo_archivo,
            "created_at" => date('Y-m-d H:i:s')
        ]
    ]);
} else {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al guardar el archivo en el servidor"]);
}
