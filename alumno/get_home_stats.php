<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

if (!$jugador_id) {
    http_response_code(400);
    echo json_encode(["error" => "jugador_id es obligatorio"]);
    exit;
}

try {
    // 1. Obtener nombre del usuario
    $stmtUser = $conn->prepare("SELECT nombre FROM usuarios WHERE id = ?");
    $stmtUser->bind_param("i", $jugador_id);
    $stmtUser->execute();
    $resUser = $stmtUser->get_result()->fetch_assoc();
    $nombre = $resUser['nombre'] ?? 'Jugador';

    // 2. Información de Packs (clases pagadas, reservadas, disponibles)
    $stmtPacks = $conn->prepare("
                SELECT
                    p.id AS id,
                    p.nombre AS nombre,
                    (compras.cantidad_compras * p.sesiones_totales) AS sesiones_totales,
                    COALESCE(reservas.clases_reservadas, 0) AS clases_reservadas,
                    COALESCE(reservas.sesiones_gastadas, 0) AS sesiones_gastadas
                FROM packs p
                JOIN (
                    SELECT 
                        pj.pack_id,
                        COUNT(*) AS cantidad_compras
                    FROM pack_jugadores pj
                    WHERE pj.jugador_id = ?
                    GROUP BY pj.pack_id
                ) compras ON compras.pack_id = p.id
                LEFT JOIN (
                    SELECT
                        r.pack_id,
                        COUNT(DISTINCT CASE WHEN r.estado = 'reservado' THEN r.id END) AS clases_reservadas,
                        COUNT(DISTINCT r.id) AS sesiones_gastadas
                    FROM reservas r
                    JOIN reserva_jugadores rj 
                    ON rj.reserva_id = r.id
                    WHERE rj.jugador_id = ?
                    AND r.estado != 'cancelado'
                    AND (r.tipo != 'grupal' OR r.tipo IS NULL)
                    GROUP BY r.pack_id
                ) reservas ON reservas.pack_id = p.id
                WHERE (p.tipo != 'grupal' OR p.tipo IS NULL)
    ");
    $stmtPacks->bind_param("ii", $jugador_id, $jugador_id);
    $stmtPacks->execute();
    $resultPacks = $stmtPacks->get_result();
    
    $packs = [];
    $clasesPagadas = 0;
    $clasesReservadas = 0;
    $clasesDisponibles = 0;
    
    while ($pack = $resultPacks->fetch_assoc()) {
        $cantidad = (int)$pack['sesiones_totales'];
        $reservadas = (int)$pack['clases_reservadas'];
        $gastadas = (int)($pack['sesiones_gastadas'] ?? 0);
        $disponibles = $cantidad - $gastadas;
        
        $clasesPagadas += $cantidad;
        $clasesReservadas += $reservadas;
        $clasesDisponibles += max(0, $disponibles);
        
        $packs[] = [
            'id' => $pack['id'],
            'nombre' => $pack['nombre'],
            'total' => $cantidad,
            'reservadas' => $reservadas,
            'disponibles' => max(0, $disponibles)
        ];
    }

    // 3. Clases Grupales (Inscripciones actuales y antiguas en packs recurrentes)
    $stmtGrupales = $conn->prepare("
        SELECT COUNT(*) as total
        FROM inscripciones_grupales ig 
        JOIN packs pg ON pg.id = ig.pack_id 
        WHERE ig.jugador_id = ? 
          AND ig.estado != 'cancelado'
    ");
    $stmtGrupales->bind_param("i", $jugador_id);
    $stmtGrupales->execute();
    $resGrupales = $stmtGrupales->get_result()->fetch_assoc();
    $clasesGrupales = (int)($resGrupales['total'] ?? 0);

    // 4. Obtener PRÓXIMA clase agendada
    $stmtNext = $conn->prepare("
        SELECT 
            r.fecha, 
            r.hora_inicio, 
            u.nombre as entrenador, 
            p.nombre as pack_nombre
        FROM reservas r
        JOIN reserva_jugadores rj ON rj.reserva_id = r.id
        JOIN usuarios u ON r.entrenador_id = u.id
        LEFT JOIN packs p ON r.pack_id = p.id
        WHERE rj.jugador_id = ? 
          AND r.estado = 'reservado'
          AND (r.fecha > CURDATE() OR (r.fecha = CURDATE() AND r.hora_inicio > CURTIME()))
        ORDER BY r.fecha ASC, r.hora_inicio ASC
        LIMIT 1
    ");
    $stmtNext->bind_param("i", $jugador_id);
    $stmtNext->execute();
    $prox_clase = $stmtNext->get_result()->fetch_assoc();

    echo json_encode([
        "nombre" => $nombre,
        "prox_clase" => $prox_clase,
        "estadisticas" => [
            "packs" => [
                "pagadas" => $clasesPagadas,
                "reservadas" => $clasesReservadas,
                "disponibles" => $clasesDisponibles,
                "grupales" => $clasesGrupales,
                "detalle" => $packs
            ]
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}

$conn->close();
