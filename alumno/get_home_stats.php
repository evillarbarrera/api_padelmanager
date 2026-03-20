<?php
// error_reporting(0);
// ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$headers = getallheaders();
require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
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

    // 2. Información de Packs (clases pagadas, reservadas, disponibles, pendientes)
    $stmtPacks = $conn->prepare("
                SELECT
                    p.id AS id,
                    p.nombre AS nombre,
                    p.entrenador_id,
                    u_e.nombre AS entrenador_nombre,
                    u_e.foto_perfil AS entrenador_foto,
                    COALESCE((
                        SELECT SUM(p_sub.sesiones_totales)
                        FROM pack_jugadores pj_sub
                        JOIN packs p_sub ON p_sub.id = pj_sub.pack_id
                        WHERE pj_sub.jugador_id = ? AND pj_sub.pack_id = p.id
                    ), p.sesiones_totales) AS sesiones_totales,
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
                    ) AS sesiones_pasadas,
                    (
                        SELECT COUNT(DISTINCT r.id)
                        FROM reservas r
                        JOIN reserva_jugadores rj ON rj.reserva_id = r.id
                        WHERE rj.jugador_id = ?
                          AND r.pack_id = p.id
                          AND r.estado != 'cancelado'
                          AND r.tipo NOT IN ('grupal', 'pack_grupal')
                          AND (r.fecha > CURDATE() OR (r.fecha = CURDATE() AND r.hora_fin > CURTIME()))
                    ) AS clases_reservadas_futuro
                FROM packs p
                JOIN usuarios u_e ON u_e.id = p.entrenador_id
                JOIN pack_jugadores pj ON pj.pack_id = p.id
                WHERE pj.jugador_id = ?
                  AND p.tipo NOT IN ('grupal', 'pack_grupal')
                GROUP BY p.id
    ");
    $stmtPacks->bind_param("iiiii", $jugador_id, $jugador_id, $jugador_id, $jugador_id, $jugador_id);
    $stmtPacks->execute();
    $resultPacks = $stmtPacks->get_result();
    
    $packs = [];
    $clasesPagadas = 0;
    $clasesReservadas = 0;
    $clasesDisponibles = 0; // Créditos sin agendar
    $clasesPendientes = 0;  // Sin agendar + Futuras
    $totalFuturas = 0;
    
    while ($pack = $resultPacks->fetch_assoc()) {
        $total = (int)$pack['sesiones_totales'];
        $pasadas = (int)$pack['sesiones_pasadas'];
        $reservadas_totales = (int)$pack['clases_reservadas_total'];
        $futuras = (int)$pack['clases_reservadas_futuro'];
        
        // Pendientes = Total Compradas - Clases ya Pasadas
        $pendientes = max(0, $total - $pasadas);
        
        // Sin agendar = Total - Todas las agendadas (no canceladas)
        $sin_agendar = max(0, $total - $reservadas_totales);
        
        $clasesPagadas += $total;
        $clasesReservadas += $reservadas_totales; // Res: Todas no canceladas
        $clasesDisponibles += $sin_agendar;
        $clasesPendientes += $pendientes;
        $totalFuturas += $futuras;
        
        if ($pendientes > 0 || $total > 0) {
            $packs[] = [
                'id' => $pack['id'],
                'nombre' => $pack['nombre'],
                'entrenador_id' => $pack['entrenador_id'],
                'entrenador_nombre' => $pack['entrenador_nombre'],
                'entrenador_foto' => $pack['entrenador_foto'],
                'total' => $total,
                'reservadas' => $reservadas_totales,
                'futuras' => $futuras,
                'pasadas' => $pasadas,
                'disponibles' => $sin_agendar,
                'pendientes' => $pendientes
            ];
        }
    }

    // 3. Clases Grupales (All-time non-cancelled group sessions)
    $stmtGrupales = $conn->prepare("
        SELECT COUNT(DISTINCT r.id) as total
        FROM reservas r
        JOIN reserva_jugadores rj ON rj.reserva_id = r.id
        WHERE rj.jugador_id = ? 
          AND r.estado != 'cancelado'
          AND (r.tipo = 'grupal' OR r.tipo = 'pack_grupal')
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
                "pendientes" => $clasesPendientes,
                "futuras" => $totalFuturas,
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
