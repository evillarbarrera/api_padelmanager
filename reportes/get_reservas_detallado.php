<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$club_id = $_GET['club_id'] ?? null;
$fecha_inicio = $_GET['finicio'] ?? '';
$fecha_fin = $_GET['ffin'] ?? '';

if (empty($fecha_inicio)) {
    $fecha_inicio = date('Y-m-01');
}
if (empty($fecha_fin)) {
    $fecha_fin = date('Y-m-d');
}

if (!$club_id) {
    http_response_code(400);
    echo json_encode(["error" => "club_id es requerido"]);
    exit;
}

try {
    /** @var PDO $pdo */
    $sql = "SELECT r.*, c.nombre as cancha_nombre, 
                   COALESCE(u1.nombre, r.nombre_externo) as jugador_nombre,
                   COALESCE(u2.nombre, r.nombre_externo2) as jugador2_nombre,
                   COALESCE(u3.nombre, r.nombre_externo3) as jugador3_nombre,
                   COALESCE(u4.nombre, r.nombre_externo4) as jugador4_nombre
            FROM reservas_cancha r
            JOIN canchas c ON r.cancha_id = c.id
            LEFT JOIN usuarios u1 ON r.usuario_id = u1.id
            LEFT JOIN usuarios u2 ON r.jugador2_id = u2.id
            LEFT JOIN usuarios u3 ON r.jugador3_id = u3.id
            LEFT JOIN usuarios u4 ON r.jugador4_id = u4.id
            WHERE c.club_id = ? AND r.fecha BETWEEN ? AND ? AND r.estado != 'Cancelada'
            ORDER BY r.fecha DESC, r.hora_inicio DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$club_id, $fecha_inicio, $fecha_fin]);
    $reservas = $stmt->fetchAll();

    echo json_encode(["success" => true, "reservas" => $reservas]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Error al obtener reservas: " . $e->getMessage()]);
}
?>
