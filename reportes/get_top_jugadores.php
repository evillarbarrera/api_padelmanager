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
    
    if ($fecha_inicio && $fecha_fin) {
        $sqlMatches = "SELECT combined.nombre, 
                               MAX(combined.email) as email, 
                               COUNT(*) as value
                        FROM (
                            SELECT COALESCE(u1.nombre, r.nombre_externo) as nombre, COALESCE(u1.usuario, '') as email, r.cancha_id, r.fecha 
                            FROM reservas_cancha r
                            LEFT JOIN usuarios u1 ON r.usuario_id = u1.id
                            WHERE r.estado != 'Cancelada' AND r.estado != 'Bloqueada' AND (r.usuario_id IS NOT NULL OR (r.nombre_externo IS NOT NULL AND r.nombre_externo != ''))
                            
                            UNION ALL
                            
                            SELECT COALESCE(u2.nombre, r.nombre_externo2) as nombre, COALESCE(u2.usuario, '') as email, r.cancha_id, r.fecha 
                            FROM reservas_cancha r
                            LEFT JOIN usuarios u2 ON r.jugador2_id = u2.id
                            WHERE r.estado != 'Cancelada' AND r.estado != 'Bloqueada' AND (r.jugador2_id IS NOT NULL OR (r.nombre_externo2 IS NOT NULL AND r.nombre_externo2 != ''))
                            
                            UNION ALL
                            
                            SELECT COALESCE(u3.nombre, r.nombre_externo3) as nombre, COALESCE(u3.usuario, '') as email, r.cancha_id, r.fecha 
                            FROM reservas_cancha r
                            LEFT JOIN usuarios u3 ON r.jugador3_id = u3.id
                            WHERE r.estado != 'Cancelada' AND r.estado != 'Bloqueada' AND (r.jugador3_id IS NOT NULL OR (r.nombre_externo3 IS NOT NULL AND r.nombre_externo3 != ''))
                            
                            UNION ALL
                            
                            SELECT COALESCE(u4.nombre, r.nombre_externo4) as nombre, COALESCE(u4.usuario, '') as email, r.cancha_id, r.fecha 
                            FROM reservas_cancha r
                            LEFT JOIN usuarios u4 ON r.jugador4_id = u4.id
                            WHERE r.estado != 'Cancelada' AND r.estado != 'Bloqueada' AND (r.jugador4_id IS NOT NULL OR (r.nombre_externo4 IS NOT NULL AND r.nombre_externo4 != ''))
                        ) as combined
                        JOIN canchas c ON combined.cancha_id = c.id
                        WHERE c.club_id = ? AND combined.fecha BETWEEN ? AND ? AND combined.nombre IS NOT NULL AND combined.nombre != ''
                        GROUP BY combined.nombre
                        ORDER BY value DESC
                        LIMIT 10";
        $stmtMatches = $pdo->prepare($sqlMatches);
        $stmtMatches->execute([$club_id, $fecha_inicio, $fecha_fin]);
        
        $sqlBookings = "SELECT COALESCE(u.nombre, r.nombre_externo) as nombre,
                               MAX(COALESCE(u.usuario, '')) as email,
                               COUNT(*) as value
                        FROM reservas_cancha r
                        JOIN canchas c ON r.cancha_id = c.id
                        LEFT JOIN usuarios u ON r.usuario_id = u.id
                        WHERE c.club_id = ? AND r.estado != 'Cancelada' AND r.estado != 'Bloqueada' AND r.fecha BETWEEN ? AND ? AND (r.usuario_id IS NOT NULL OR (r.nombre_externo IS NOT NULL AND r.nombre_externo != ''))
                        GROUP BY COALESCE(u.nombre, r.nombre_externo)
                        ORDER BY value DESC
                        LIMIT 10";
        $stmtBookings = $pdo->prepare($sqlBookings);
        $stmtBookings->execute([$club_id, $fecha_inicio, $fecha_fin]);

    } else {
        $sqlMatches = "SELECT combined.nombre, 
                               MAX(combined.email) as email, 
                               COUNT(*) as value
                        FROM (
                            SELECT COALESCE(u1.nombre, r.nombre_externo) as nombre, COALESCE(u1.usuario, '') as email, r.cancha_id 
                            FROM reservas_cancha r
                            LEFT JOIN usuarios u1 ON r.usuario_id = u1.id
                            WHERE r.estado != 'Cancelada' AND r.estado != 'Bloqueada' AND (r.usuario_id IS NOT NULL OR (r.nombre_externo IS NOT NULL AND r.nombre_externo != ''))
                            
                            UNION ALL
                            
                            SELECT COALESCE(u2.nombre, r.nombre_externo2) as nombre, COALESCE(u2.usuario, '') as email, r.cancha_id 
                            FROM reservas_cancha r
                            LEFT JOIN usuarios u2 ON r.jugador2_id = u2.id
                            WHERE r.estado != 'Cancelada' AND r.estado != 'Bloqueada' AND (r.jugador2_id IS NOT NULL OR (r.nombre_externo2 IS NOT NULL AND r.nombre_externo2 != ''))
                            
                            UNION ALL
                            
                            SELECT COALESCE(u3.nombre, r.nombre_externo3) as nombre, COALESCE(u3.usuario, '') as email, r.cancha_id 
                            FROM reservas_cancha r
                            LEFT JOIN usuarios u3 ON r.jugador3_id = u3.id
                            WHERE r.estado != 'Cancelada' AND r.estado != 'Bloqueada' AND (r.jugador3_id IS NOT NULL OR (r.nombre_externo3 IS NOT NULL AND r.nombre_externo3 != ''))
                            
                            UNION ALL
                            
                            SELECT COALESCE(u4.nombre, r.nombre_externo4) as nombre, COALESCE(u4.usuario, '') as email, r.cancha_id 
                            FROM reservas_cancha r
                            LEFT JOIN usuarios u4 ON r.jugador4_id = u4.id
                            WHERE r.estado != 'Cancelada' AND r.estado != 'Bloqueada' AND (r.jugador4_id IS NOT NULL OR (r.nombre_externo4 IS NOT NULL AND r.nombre_externo4 != ''))
                        ) as combined
                        JOIN canchas c ON combined.cancha_id = c.id
                        WHERE c.club_id = ? AND combined.nombre IS NOT NULL AND combined.nombre != ''
                        GROUP BY combined.nombre
                        ORDER BY value DESC
                        LIMIT 10";
        $stmtMatches = $pdo->prepare($sqlMatches);
        $stmtMatches->execute([$club_id]);

        $sqlBookings = "SELECT COALESCE(u.nombre, r.nombre_externo) as nombre,
                               MAX(COALESCE(u.usuario, '')) as email,
                               COUNT(*) as value
                        FROM reservas_cancha r
                        JOIN canchas c ON r.cancha_id = c.id
                        LEFT JOIN usuarios u ON r.usuario_id = u.id
                        WHERE c.club_id = ? AND r.estado != 'Cancelada' AND r.estado != 'Bloqueada' AND (r.usuario_id IS NOT NULL OR (r.nombre_externo IS NOT NULL AND r.nombre_externo != ''))
                        GROUP BY COALESCE(u.nombre, r.nombre_externo)
                        ORDER BY value DESC
                        LIMIT 10";
        $stmtBookings = $pdo->prepare($sqlBookings);
        $stmtBookings->execute([$club_id]);
    }

    $topPartidos = $stmtMatches->fetchAll();
    $topReservas = $stmtBookings->fetchAll();

    echo json_encode([
        "success" => true,
        "top_partidos" => $topPartidos,
        "top_reservas" => $topReservas
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Error de base de datos: " . $e->getMessage()]);
}
?>
