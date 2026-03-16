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

$headers = getallheaders();
$auth = $headers['Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

if ($auth !== 'Bearer ' . base64_encode("1|padel_academy")) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$jugador_id = $_GET['jugador_id'] ?? null;
if (!$jugador_id) {
    http_response_code(400);
    echo json_encode(["error" => "jugador_id requerido"]);
    exit;
}

require_once "../db.php";

$sql = "
    SELECT 
        pj.id as pack_jugador_id,
        pj.sesiones_usadas,
        pj.fecha_inicio,
        pj.fecha_fin,
        pk.id as pack_id,
        pk.nombre as pack_nombre,
        pk.sesiones_totales,
        pk.tipo,
        pk.cantidad_personas,
        pk.rango_horario_inicio,
        pk.rango_horario_fin,
        COALESCE(ig.estado, 'activo') as estado_inscripcion,
        (
            SELECT COUNT(*) 
            FROM reserva_jugadores rj2 
            JOIN reservas r2 ON rj2.reserva_id = r2.id 
            WHERE rj2.jugador_id = pj.jugador_id 
              AND r2.pack_id = pk.id 
              AND r2.estado != 'cancelado'
              AND (r2.fecha < CURDATE() OR (r2.fecha = CURDATE() AND r2.hora_fin <= CURTIME()))
        ) as sesiones_pasadas,
        (
            SELECT COUNT(*) 
            FROM reserva_jugadores rj2 
            JOIN reservas r2 ON rj2.reserva_id = r2.id 
            WHERE rj2.jugador_id = pj.jugador_id 
              AND r2.pack_id = pk.id 
              AND r2.estado != 'cancelado'
        ) as sesiones_reservadas
    FROM pack_jugadores pj
    JOIN packs pk ON pj.pack_id = pk.id
    LEFT JOIN inscripciones_grupales ig ON ig.pack_id = pk.id AND ig.jugador_id = pj.jugador_id
    WHERE (pj.jugador_id = ? OR pj.id IN (SELECT pack_jugadores_id FROM pack_jugadores_adicionales WHERE jugador_id = ? AND estado = 'aceptado'))
      AND pk.tipo != 'grupal'
    ORDER BY pj.fecha_inicio DESC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(["error" => "Prepare failed: " . $conn->error]);
    exit;
}

$stmt->bind_param("ii", $jugador_id, $jugador_id);
if (!$stmt->execute()) {
    echo json_encode(["error" => "Execute failed: " . $stmt->error]);
    exit;
}

$res = $stmt->get_result();

$packs = [];

while ($row = $res->fetch_assoc()) {
    // Buscar invitados para este pack específico
    $invitados = [];
    if (($row['cantidad_personas'] ?? 1) > 1) {
        $sqlInv = "
            SELECT 
                u.id, 
                u.nombre, 
                u.usuario, 
                pja.estado,
                pja.fecha_asignacion 
            FROM pack_jugadores_adicionales pja
            JOIN usuarios u ON pja.jugador_id = u.id
            WHERE pja.pack_jugadores_id = ?
        ";
        $stmtInv = $conn->prepare($sqlInv);
        if ($stmtInv) {
            $stmtInv->bind_param("i", $row['pack_jugador_id']);
            $stmtInv->execute();
            $resInv = $stmtInv->get_result();
            while($inv = $resInv->fetch_assoc()){
                $invitados[] = $inv;
            }
        }
    }
    
    $row['invitados'] = $invitados;
    $packs[] = $row;
}

echo json_encode(["success" => true, "data" => $packs]);
?>
