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
require_once "init_tables.php";
ensureCoachPublicProfileTables($conn);

$input = json_decode(file_get_contents("php://input"), true);
$slug = trim($input['slug'] ?? '');
$entrenador_id = intval($input['entrenador_id'] ?? 0);

if (empty($slug) && $entrenador_id <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Slug o entrenador_id requerido"]);
    exit;
}

try {
    if (!empty($slug)) {
        $stmt = $conn->prepare("UPDATE entrenador_perfil_publico SET visitas = visitas + 1 WHERE slug = ?");
        $stmt->bind_param("s", $slug);
    } else {
        $stmt = $conn->prepare("UPDATE entrenador_perfil_publico SET visitas = visitas + 1 WHERE entrenador_id = ?");
        $stmt->bind_param("i", $entrenador_id);
    }
    $stmt->execute();

    echo json_encode(["status" => "success"]);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
