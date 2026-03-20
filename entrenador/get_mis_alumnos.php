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
require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}

require_once "../db.php";

$entrenador_id = $_GET['entrenador_id'] ?? 0;

if (!$entrenador_id) {
    http_response_code(400);
    echo json_encode(["error" => "entrenador_id es requerido"]);
    exit;
}

try {
    // Buscar jugadores que han comprado packs a este entrenador
    // Y traer información de packs INDIVIDUALES con sesiones disponibles
    $sql = "
        SELECT 
            u.id as id,
            u.id as jugador_id,
            u.nombre as nombre,
            u.nombre as jugador_nombre,
            u.usuario as usuario,
            u.foto_perfil as jugador_foto,
            p.id as pack_id,
            p.nombre as pack_nombre,
            SUM(p.sesiones_totales) as sesiones_totales,
            MAX(pj.id) as pack_jugador_id,
            p.rango_horario_inicio,
            p.rango_horario_fin,
            (
                SELECT COUNT(*) 
                FROM reservas r2 
                JOIN reserva_jugadores rj2 ON r2.id = rj2.reserva_id
                WHERE rj2.jugador_id = u.id 
                  AND r2.pack_id = p.id 
                  AND r2.estado != 'cancelado'
            ) as sesiones_totales_reservadas,
            (
                SELECT COUNT(*) 
                FROM reservas r2 
                JOIN reserva_jugadores rj2 ON r2.id = rj2.reserva_id
                WHERE rj2.jugador_id = u.id 
                  AND r2.pack_id = p.id 
                  AND r2.estado != 'cancelado'
                  AND (r2.fecha < CURDATE() OR (r2.fecha = CURDATE() AND r2.hora_fin <= CURTIME()))
            ) as sesiones_pasadas
        FROM usuarios u
        JOIN pack_jugadores pj ON u.id = pj.jugador_id
        JOIN packs p ON pj.pack_id = p.id
        WHERE p.entrenador_id = ? 
          AND p.tipo NOT IN ('grupal', 'pack_grupal')
        GROUP BY u.id, p.id
        ORDER BY u.nombre ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $entrenador_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $alumnos = [];
    while ($row = $result->fetch_assoc()) {
        $total = (int)$row['sesiones_totales'];
        $pasadas = (int)$row['sesiones_pasadas'];
        $reservadas = (int)$row['sesiones_totales_reservadas'];
        
        $pendientes = max(0, $total - $pasadas);
        $disponibles = max(0, $total - $reservadas);
        
        $row['sesiones_restantes'] = $disponibles;
        $row['sesiones_disponibles'] = $disponibles;
        $row['sesiones_pendientes'] = $pendientes;
        $row['sesiones_reservadas'] = $reservadas;
        $row['pasadas'] = $pasadas;
        $alumnos[] = $row;
    }

    echo json_encode($alumnos);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}

$conn->close();
?>
