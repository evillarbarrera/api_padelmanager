<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../auth/auth_helper.php";
$userId = validateToken();
if (!$userId) {
    sendUnauthorized("Token inválido o faltante");
}

require_once "../db.php";

$entrenador_id = $_GET['entrenador_id'] ?? null;

if (!$entrenador_id) {
    http_response_code(400);
    echo json_encode(["error" => "entrenador_id es obligatorio"]);
    exit;
}

// Consulta con JOIN para detectar tipos grupales de forma eficiente
$sql = "
    SELECT 
        d.id,
        d.fecha_inicio,
        d.fecha_fin,
        IF(p.id IS NOT NULL, 'grupal', 'individual') as tipo_real,
        IF(EXISTS(SELECT 1 FROM reservas r2 WHERE r2.entrenador_id = d.profesor_id AND r2.fecha = DATE(d.fecha_inicio) AND r2.hora_inicio = TIME(d.fecha_inicio) AND r2.estado = 'reservado'), 1, 0) as ocupado,
        p.id as pack_id,
        (SELECT COUNT(*) FROM reserva_jugadores rj JOIN reservas r3 ON r3.id = rj.reserva_id WHERE r3.entrenador_id = d.profesor_id AND r3.fecha = DATE(d.fecha_inicio) AND r3.hora_inicio = TIME(d.fecha_inicio) AND r3.estado = 'reservado') as inscritos_count,
        IFNULL(p.capacidad_maxima, 6) as cantidad_personas,
        IFNULL(p.nombre, 'Clase') as nombre_pack,
        COALESCE(p.club_id, d.club_id) as club_id,
        c.nombre as club_nombre,
        c.direccion as club_direccion,
        c.google_maps_link as club_maps,
        u_ent.telefono as entrenador_telefono
    FROM disponibilidad_profesor d
    INNER JOIN usuarios u_ent ON u_ent.id = d.profesor_id
    LEFT JOIN clubes c ON c.id = d.club_id
    LEFT JOIN packs p ON p.entrenador_id = d.profesor_id 
        AND p.tipo = 'grupal' 
        AND p.activo = 1 
        AND p.permite_inscripcion = 1
        AND (p.fecha = DATE(d.fecha_inicio) OR (p.fecha IS NULL AND p.dia_semana = (WEEKDAY(d.fecha_inicio))))
        AND (p.hora_inicio IS NULL OR p.hora_inicio = TIME(d.fecha_inicio))
    WHERE d.profesor_id = ? AND d.activo = 1
      AND d.fecha_inicio >= DATE_SUB(NOW(), INTERVAL 60 DAY)
      AND d.fecha_inicio <= DATE_ADD(NOW(), INTERVAL 35 DAY)

UNION ALL

    SELECT 
        r.id as id,
        CONCAT(r.fecha, ' ', r.hora_inicio) as fecha_inicio,
        CONCAT(r.fecha, ' ', r.hora_fin) as fecha_fin,
        r.tipo as tipo_real,
        0 as ocupado,
        r.pack_id as pack_id,
        (SELECT COUNT(*) FROM reserva_jugadores rj2 WHERE rj2.reserva_id = r.id) as inscritos_count,
        6 as cantidad_personas,
        'Clase Grupal' as nombre_pack,
        r.club_id,
        c.nombre as club_nombre,
        c.direccion as club_direccion,
        c.google_maps_link as club_maps,
        u_ent.telefono as entrenador_telefono
    FROM reservas r
    INNER JOIN usuarios u_ent ON u_ent.id = r.entrenador_id
    LEFT JOIN clubes c ON c.id = r.club_id
    WHERE r.entrenador_id = ? 
      AND r.estado = 'reservado' 
      AND r.fecha >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
      AND r.fecha <= DATE_ADD(CURDATE(), INTERVAL 35 DAY)
";

try {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(["error" => "Error prepare: " . $conn->error]);
        exit;
    }
    $stmt->bind_param("ii", $entrenador_id, $entrenador_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $row['tipo'] = $row['tipo_real'];
        $data[] = $row;
    }

    echo json_encode($data);
    $stmt->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}

$conn->close();
?>
