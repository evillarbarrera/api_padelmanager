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

$coachId = validateToken();
if (!$coachId) {
    sendUnauthorized("Token inválido");
}

$input = json_decode(file_get_contents("php://input"), true);
$resenaId = intval($input['resena_id'] ?? 0);
$respuesta = trim($input['respuesta'] ?? '');

if ($resenaId <= 0 || empty($respuesta)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Datos incompletos"]);
    exit;
}

try {
    $stmt = $conn->prepare("UPDATE resenas_entrenador 
                            SET respuesta_entrenador = ?, fecha_respuesta = NOW() 
                            WHERE id = ? AND entrenador_id = ?");
    $stmt->bind_param("sii", $respuesta, $resenaId, $coachId);

    if ($stmt->execute() && $stmt->affected_rows >= 0) {
        echo json_encode([
            "status" => "success",
            "message" => "Respuesta guardada con éxito",
            "fecha_respuesta" => date('Y-m-d H:i:s')
        ]);
    } else {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "No tienes permisos para responder esta reseña"]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
