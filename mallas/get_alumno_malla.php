<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
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


error_reporting(0);
ini_set('display_errors', 0);
ob_start();

require_once "../db.php";

$jugador_id = $_GET['jugador_id'] ?? 0;
$entrenador_id = $_GET['entrenador_id'] ?? 0;

if (!$jugador_id) {
    echo json_encode(["error" => "jugador_id es requerido"]);
    exit;
}

$sql = "
    SELECT 
        ams.id as seguimiento_id,
        ams.estado,
        ams.clase_actual_orden,
        m.id as malla_id,
        m.nombre as malla_nombre,
        m.nivel as malla_nivel,
        (SELECT COUNT(*) FROM clases_malla WHERE malla_id = m.id) as total_clases,
        (SELECT COUNT(*) FROM alumno_asistencia WHERE alumno_malla_id = ams.id AND estado_asistencia = 'presente') as clases_asistidas
    FROM alumno_malla_seguimiento ams
    JOIN mallas m ON ams.malla_id = m.id
    WHERE ams.jugador_id = ? AND ams.entrenador_id = ? AND ams.estado = 'activo'
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode(["error" => "Error preparando consulta (¿Falta la tabla?): " . $conn->error]);
    exit;
}

$stmt->bind_param("ii", $jugador_id, $entrenador_id);
if ($stmt->execute()) {
    $res = $stmt->get_result()->fetch_assoc();
    echo json_encode($res ?: null);
} else {
    echo json_encode(["error" => "Error ejecutando consulta: " . $stmt->error]);
}
?>
