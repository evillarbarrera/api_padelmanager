<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$token = $_GET['token'] ?? null;

if (!$token) {
    http_response_code(400);
    echo json_encode(["error" => "Token no proporcionado"]);
    exit;
}

$sql = "
    SELECT 
        pja.id as invitation_id,
        pja.estado,
        u_owner.nombre as owner_nombre,
        p.nombre as pack_nombre,
        p.sesiones_totales,
        p.tipo
    FROM pack_jugadores_adicionales pja
    JOIN pack_jugadores pj ON pja.pack_jugadores_id = pj.id
    JOIN packs p ON pj.pack_id = p.id
    JOIN usuarios u_owner ON pj.jugador_id = u_owner.id
    WHERE pja.token = ?
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $token);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();

if (!$res) {
    http_response_code(404);
    echo json_encode(["error" => "Invitación no encontrada o expirada"]);
    exit;
}

echo json_encode(["success" => true, "data" => $res]);
?>
