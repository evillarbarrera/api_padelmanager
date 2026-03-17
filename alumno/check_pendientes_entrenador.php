<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
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

require_once "../db.php";

$jugador_id = $_GET['jugador_id'] ?? null;
$entrenador_id = $_GET['entrenador_id'] ?? null;

if (!$jugador_id || !$entrenador_id) {
    http_response_code(400);
    echo json_encode(["error" => "jugador_id y entrenador_id son obligatorios"]);
    exit;
}

try {
    // Buscar todos los packs que el jugador tenga con ese entrenador
    $stmt = $conn->prepare("
        SELECT 
            p.id,
            COALESCE((
                SELECT SUM(pj_sub.sesiones_totales)
                FROM pack_jugadores pj_sub
                WHERE pj_sub.jugador_id = ? AND pj_sub.pack_id = p.id
            ), 0) AS sesiones_totales,
            (
                SELECT COUNT(DISTINCT r.id)
                FROM reservas r
                JOIN reserva_jugadores rj ON rj.reserva_id = r.id
                WHERE rj.jugador_id = ? 
                  AND r.pack_id = p.id
                  AND r.estado != 'cancelado'
                  AND r.tipo NOT IN ('grupal', 'pack_grupal')
            ) AS clases_reservadas_total,
            (
                SELECT COUNT(DISTINCT r.id)
                FROM reservas r
                JOIN reserva_jugadores rj ON rj.reserva_id = r.id
                WHERE rj.jugador_id = ? 
                  AND r.pack_id = p.id
                  AND r.estado != 'cancelado'
                  AND r.tipo NOT IN ('grupal', 'pack_grupal')
                  AND (r.fecha < CURDATE() OR (r.fecha = CURDATE() AND r.hora_fin <= CURTIME()))
            ) AS sesiones_pasadas
        FROM packs p
        JOIN pack_jugadores pj ON pj.pack_id = p.id
        WHERE pj.jugador_id = ? 
          AND p.entrenador_id = ?
          AND p.tipo NOT IN ('grupal', 'pack_grupal')
        GROUP BY p.id
    ");
    $stmt->bind_param("iiiii", $jugador_id, $jugador_id, $jugador_id, $jugador_id, $entrenador_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $totalPendientes = 0;
    $totalDisponibles = 0;

    while ($row = $result->fetch_assoc()) {
        $total = (int)$row['sesiones_totales'];
        $pasadas = (int)$row['sesiones_pasadas'];
        $reservadas_totales = (int)$row['clases_reservadas_total'];

        $pendientes = max(0, $total - $pasadas);
        $disponibles = max(0, $total - $reservadas_totales);

        $totalPendientes += $pendientes;
        $totalDisponibles += $disponibles;
    }

    echo json_encode([
        "success" => true,
        "entrenador_id" => $entrenador_id,
        "pendientes" => $totalPendientes,
        "disponibles" => $totalDisponibles
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}

$conn->close();
