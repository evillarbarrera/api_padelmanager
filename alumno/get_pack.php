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

// jugador_id
$jugador_id = $_GET['jugador_id'] ?? null;

if (!$jugador_id) {
    http_response_code(400);
    echo json_encode(["error" => "jugador_id es obligatorio"]);
    exit;
}

// Query
$sql = "
SELECT 
    p.id AS pack_id,
    p.nombre AS pack_nombre,

    /* total real de sesiones compradas */
    SUM(p.sesiones_totales) AS debug_total,

    /* sesiones usadas reales */
    (
        SELECT COUNT(DISTINCT r.id)
        FROM reservas r
        JOIN reserva_jugadores rj 
          ON r.id = rj.reserva_id
        WHERE r.pack_id = p.id
          AND rj.jugador_id = cp.jugador_id
          AND r.estado != 'cancelado'
    ) AS debug_used,

    /* sesiones restantes */
    (
        SUM(p.sesiones_totales)
        -
        (
            SELECT COUNT(DISTINCT r.id)
            FROM reservas r
            JOIN reserva_jugadores rj 
              ON r.id = rj.reserva_id
            WHERE r.pack_id = p.id
              AND rj.jugador_id = cp.jugador_id
              AND r.estado != 'cancelado'
        )
    ) AS sesiones_restantes,

    e.id AS entrenador_id,
    e.nombre AS entrenador_nombre,
    e.foto_perfil AS entrenador_foto,

    p.tipo,
    p.cantidad_personas,
    p.rango_horario_inicio,
    p.rango_horario_fin,

    MIN(cp.fecha_inicio) AS fecha_compra_pack

FROM pack_jugadores cp
JOIN packs p ON p.id = cp.pack_id
JOIN usuarios e ON e.id = p.entrenador_id
WHERE cp.jugador_id = ?
GROUP BY 
    p.id,
    p.nombre,
    p.tipo,
    p.cantidad_personas,
    p.rango_horario_inicio,
    p.rango_horario_fin,
    e.id,
    e.nombre,
    e.foto_perfil;
";  

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $jugador_id);
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
  $data[] = $row;
}

echo json_encode($data);
