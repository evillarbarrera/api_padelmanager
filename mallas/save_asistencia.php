<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}


require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$reserva_id = $data['reserva_id'] ?? 0;
$jugador_id = $data['jugador_id'] ?? 0;
$asistencia = $data['estado_asistencia'] ?? 'presente';
$feedback = $data['feedback_coach'] ?? '';
$objetivos_logrados = $data['objetivos_logrados'] ?? '[]'; // JSON array of indices
$seguimiento_id = $data['alumno_malla_id'] ?? 0;
$clase_malla_id = $data['clase_malla_id'] ?? 0;
$entrenador_id = $data['entrenador_id'] ?? 0;

if (!$jugador_id || !$clase_malla_id) {
    http_response_code(400); echo json_encode(["error" => "Faltan IDs indispensables"]); exit;
}

// 1. Upsert to alumno_asistencia
$sql_check = "SELECT id FROM alumno_asistencia WHERE alumno_malla_id = ? AND reserva_id = ?";
$stmt = $conn->prepare($sql_check);
$stmt->bind_param("ii", $seguimiento_id, $reserva_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows > 0) {
    // Update
    $h_id = $res->fetch_assoc()['id'];
    $sql_upd = "UPDATE alumno_asistencia 
                SET estado_asistencia = ?, feedback_coach = ?, objetivos_logrados = ?, entrenador_id = ?
                WHERE id = ?";
    $stmt_upd = $conn->prepare($sql_upd);
    $stmt_upd->bind_param("sssii", $asistencia, $feedback, $objetivos_logrados, $entrenador_id, $h_id);
    $stmt_upd->execute();
} else {
    // Insert new history record for this class
    $sql_ins = "INSERT INTO alumno_asistencia (alumno_malla_id, clase_malla_id, jugador_id, reserva_id, entrenador_id, estado_asistencia, feedback_coach, objetivos_logrados)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt_ins = $conn->prepare($sql_ins);
    $stmt_ins->bind_param("iiiiisss", $seguimiento_id, $clase_malla_id, $jugador_id, $reserva_id, $entrenador_id, $asistencia, $feedback, $objetivos_logrados);
    $stmt_ins->execute();
}

// 2. If present, maybe increment progress in seguimiento
if ($asistencia === 'presente') {
    $conn->query("UPDATE alumno_malla_seguimiento 
                  SET clases_asistidas = clases_asistidas + 1,
                      clase_actual_orden = clase_actual_orden + 1
                  WHERE id = $seguimiento_id");
}

// 3. Check achievements (constancy badges)
$nuevosLogros = [];
if ($asistencia === 'presente' && $jugador_id > 0) {
    require_once __DIR__ . "/../logros/check_achievements.php";
    $nuevosLogros = checkAchievements($conn, $jugador_id);
}

echo json_encode(["success" => true, "nuevos_logros" => $nuevosLogros]);
?>
