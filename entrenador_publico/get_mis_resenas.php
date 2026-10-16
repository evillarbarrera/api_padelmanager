<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
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

$coachId = validateToken();
if (!$coachId) {
    sendUnauthorized("Token inválido");
}

try {
    $sql = "SELECT r.*, u.nombre as alumno_nombre, COALESCE(u.foto_perfil, u.foto) as alumno_foto, u.usuario as alumno_email
            FROM resenas_entrenador r
            INNER JOIN usuarios u ON u.id = r.alumno_id
            WHERE r.entrenador_id = ?
            ORDER BY r.created_at DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $coachId);
    $stmt->execute();
    $res = $stmt->get_result();

    $resenas = [];
    $totalRating = 0;
    while ($row = $res->fetch_assoc()) {
        $totalRating += intval($row['rating']);
        $resenas[] = $row;
    }

    $count = count($resenas);
    $promedio = $count > 0 ? round($totalRating / $count, 1) : 5.0;

    echo json_encode([
        "status" => "success",
        "resenas" => $resenas,
        "estadisticas" => [
            "total" => $count,
            "promedio" => $promedio
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
