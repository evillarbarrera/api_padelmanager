<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$clubId = intval($data['club_id'] ?? 0);

if ($clubId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "ID de club inválido"]);
    exit;
}

// Auto-crear columnas necesarias en la tabla clubes si no existen
$columns = [
    "landing_slug" => "VARCHAR(100) DEFAULT NULL",
    "landing_publicado" => "TINYINT(1) DEFAULT 0",
    "landing_descripcion" => "TEXT DEFAULT NULL",
    "landing_banner" => "VARCHAR(255) DEFAULT NULL",
    "landing_whatsapp" => "VARCHAR(50) DEFAULT NULL",
    "landing_instagram" => "VARCHAR(100) DEFAULT NULL",
    "landing_facebook" => "VARCHAR(100) DEFAULT NULL",
    "landing_mostrar_reservas" => "TINYINT(1) DEFAULT 1",
    "landing_mostrar_americanos" => "TINYINT(1) DEFAULT 1",
    "landing_mostrar_ranking" => "TINYINT(1) DEFAULT 1"
];

foreach ($columns as $col => $type) {
    $check = $conn->query("SHOW COLUMNS FROM clubes LIKE '$col'");
    if ($check && $check->num_rows === 0) {
        @$conn->query("ALTER TABLE clubes ADD COLUMN $col $type");
    }
}

// Limpiar y formatear slug
$slugRaw = trim($data['slug'] ?? '');
if (empty($slugRaw)) {
    // Generar slug por defecto basado en ID o nombre
    $stmtC = $conn->prepare("SELECT nombre FROM clubes WHERE id = ?");
    $stmtC->bind_param("i", $clubId);
    $stmtC->execute();
    $rC = $stmtC->get_result()->fetch_assoc();
    $slugRaw = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $rC['nombre'] ?? "club-$clubId"));
} else {
    $slugRaw = strtolower(preg_replace('/[^a-zA-Z0-9\-]+/', '', str_replace(' ', '-', $slugRaw)));
}

// Validar que el slug no esté en uso por otro club
$stmtCheck = $conn->prepare("SELECT id FROM clubes WHERE landing_slug = ? AND id != ?");
$stmtCheck->bind_param("si", $slugRaw, $clubId);
$stmtCheck->execute();
if ($stmtCheck->get_result()->num_rows > 0) {
    echo json_encode([
        "status" => "error",
        "message" => "El enlace '$slugRaw' ya está siendo utilizado por otro club. Por favor elige otro."
    ]);
    exit;
}

$publicado = intval($data['publicado'] ?? 0);
$descripcion = trim($data['descripcion_landing'] ?? '');
$banner = trim($data['banner_url'] ?? '');
$whatsapp = trim($data['whatsapp'] ?? '');
$instagram = trim($data['instagram'] ?? '');
$facebook = trim($data['facebook'] ?? '');
$mostrarReservas = intval($data['mostrar_reservas'] ?? 1);
$mostrarAmericanos = intval($data['mostrar_americanos'] ?? 1);
$mostrarRanking = intval($data['mostrar_ranking'] ?? 1);

// Actualizar configuración
$sql = "UPDATE clubes SET 
        landing_slug = ?, 
        landing_publicado = ?, 
        landing_descripcion = ?, 
        landing_banner = ?, 
        landing_whatsapp = ?, 
        landing_instagram = ?, 
        landing_facebook = ?, 
        landing_mostrar_reservas = ?, 
        landing_mostrar_americanos = ?, 
        landing_mostrar_ranking = ? 
        WHERE id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param(
    "sisssssiiii",
    $slugRaw,
    $publicado,
    $descripcion,
    $banner,
    $whatsapp,
    $instagram,
    $facebook,
    $mostrarReservas,
    $mostrarAmericanos,
    $mostrarRanking,
    $clubId
);

if ($stmt->execute()) {
    echo json_encode([
        "status" => "success",
        "message" => "Configuración de Landing Page guardada con éxito",
        "slug" => $slugRaw,
        "publicado" => $publicado
    ]);
} else {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error guardando configuración: " . $stmt->error]);
}

$conn->close();
?>
