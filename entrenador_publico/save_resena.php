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

$alumnoId = validateToken();
if (!$alumnoId) {
    sendUnauthorized("Debes iniciar sesión para dejar una reseña");
}

$input = json_decode(file_get_contents("php://input"), true);
$entrenador_id = intval($input['entrenador_id'] ?? 0);
$rating = intval($input['rating'] ?? 0);
$comentario = trim($input['comentario'] ?? '');

if ($entrenador_id <= 0 || $rating < 1 || $rating > 5 || empty($comentario)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Debes seleccionar una calificación (1-5) y escribir un comentario"]);
    exit;
}

try {
    // 1. REGLA DE INTEGRIDAD: Verificar que el alumno haya tomado al menos 1 clase con el entrenador
    // (ya sea como titular de reserva individual, jugador en reserva grupal, o relación en entrenador_alumno)
    $sqlVerif = "SELECT 1 FROM (
                    SELECT id FROM reservas 
                    WHERE entrenador_id = ? AND (jugador_id = ? OR usuario_id = ?)
                    
                    UNION 
                    
                    SELECT r.id FROM reservas r
                    JOIN reserva_jugadores rj ON rj.reserva_id = r.id
                    WHERE r.entrenador_id = ? AND rj.jugador_id = ?
                    
                    UNION
                    
                    SELECT id FROM entrenador_alumno
                    WHERE entrenador_id = ? AND alumno_id = ?
                 ) as t LIMIT 1";

    $stmtV = $conn->prepare($sqlVerif);
    $stmtV->bind_param("iiiiiii", $entrenador_id, $alumnoId, $alumnoId, $entrenador_id, $alumnoId, $entrenador_id, $alumnoId);
    $stmtV->execute();
    $resV = $stmtV->get_result();

    if ($resV->num_rows === 0) {
        http_response_code(403);
        echo json_encode([
            "status" => "error",
            "message" => "Para garantizar reseñas 100% auténticas, solo los alumnos que han tenido clases con este entrenador pueden calificarlo."
        ]);
        exit;
    }

    // 2. Insertar o actualizar reseña (1 reseña editable por alumno)
    $sql = "INSERT INTO resenas_entrenador (entrenador_id, alumno_id, rating, comentario, visible)
            VALUES (?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE
                rating = VALUES(rating),
                comentario = VALUES(comentario),
                visible = 1,
                updated_at = NOW()";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiis", $entrenador_id, $alumnoId, $rating, $comentario);

    if ($stmt->execute()) {
        echo json_encode([
            "status" => "success",
            "message" => "¡Muchas gracias! Tu reseña ha sido publicada con éxito."
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error al guardar reseña: " . $stmt->error]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
