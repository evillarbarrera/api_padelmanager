<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Authorization
$headers = getallheaders();
require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}

require_once "../db.php";

/* PARAMS */
$profesor_id = $_GET['profesor_id'] ?? null;
$fecha = $_GET['fecha'] ?? null;

if (!$profesor_id || !$fecha) {
    http_response_code(400);
    echo json_encode(["error" => "profesor_id y fecha son obligatorios"]);
    exit;
}

/*
 Convertimos la reserva a DATETIME
 y cruzamos rangos
*/

$sql = "
SELECT
    dp.fecha_inicio AS hora_inicio,
    dp.fecha_fin AS hora_fin,
    CASE
        WHEN r.id IS NULL THEN 0
        ELSE 1
    END AS ocupado
FROM disponibilidad_profesor dp
LEFT JOIN reservas r
    ON r.entrenador_id = dp.profesor_id
    AND CONCAT(r.fecha, ' ', r.hora_inicio) < dp.fecha_fin
    AND CONCAT(r.fecha, ' ', r.hora_fin) > dp.fecha_inicio
    AND r.estado != 'cancelado'
WHERE dp.profesor_id = ?
AND DATE(dp.fecha_inicio) = ?
AND dp.activo = 1
ORDER BY dp.fecha_inicio
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $profesor_id, $fecha);
$stmt->execute();

$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = [
        "hora_inicio" => $row['hora_inicio'],
        "hora_fin" => $row['hora_fin'],
        "ocupado" => (bool)$row['ocupado']
    ];
}

echo json_encode($data);
