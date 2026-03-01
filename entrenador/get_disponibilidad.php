<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$headers = getallheaders();
$auth = $headers['Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? ($headers['authorization'] ?? ''));

if (!preg_match('/^Bearer\s+(.*)$/', $auth, $matches) || base64_decode($matches[1]) !== "1|padel_academy") {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

require_once "../db.php";

$entrenador_id = $_GET['entrenador_id'] ?? null;

if (!$entrenador_id) {
    http_response_code(400);
    echo json_encode(["error" => "entrenador_id es obligatorio"]);
    exit;
}

$sql = "
SELECT 
    d.id,
    d.fecha_inicio,
    d.fecha_fin,
    -- Determinamos si el bloque es grupal
    CASE 
        WHEN bg.id IS NOT NULL THEN 'grupal'
        WHEN r.id IS NOT NULL AND r.hora_inicio = TIME(d.fecha_inicio) AND (r.tipo = 'grupal' OR r.tipo = 'pack_grupal') THEN 'grupal'
        ELSE 'individual'
    END as tipo_real,

    -- Verificamos si está ocupado
    CASE 
        -- Si es un bloque de grupo (recurrente o reserva grupal activa)
        WHEN bg.id IS NOT NULL OR (r.id IS NOT NULL AND (r.tipo = 'grupal' OR r.tipo = 'pack_grupal')) THEN
            IF((SELECT COUNT(DISTINCT jugador_id) FROM (
                -- 1. Inscritos permanentes SIEMPRE cuentan para todas las fechas (si existen)
                SELECT jugador_id FROM inscripciones_grupales WHERE pack_id = COALESCE(bg.pack_id, r.pack_id) AND estado = 'activo'
                UNION
                -- 2. Inscritos específicos SOLO para esta fecha
                SELECT rj.jugador_id FROM reserva_jugadores rj JOIN reservas r2 ON rj.reserva_id = r2.id WHERE r2.pack_id = COALESCE(bg.pack_id, r.pack_id) AND r2.fecha = DATE(d.fecha_inicio) AND r2.estado = 'reservado'
            ) t) >= COALESCE(p2.capacidad_maxima, p_r.capacidad_maxima, 6), 1, 0)
        
        -- Si NO es grupal, pero hay CUALQUIER reserva que se solapa (clase individual ocupando el bloque)
        WHEN r_any.id IS NOT NULL THEN 1
        ELSE 0
    END as ocupado,
    
    COALESCE(bg.pack_id, r.pack_id) as pack_id,
    
    -- Conteo consolidado de inscritos
    (SELECT COUNT(DISTINCT jugador_id) FROM (
        SELECT jugador_id FROM inscripciones_grupales WHERE pack_id = COALESCE(bg.pack_id, r.pack_id) AND estado = 'activo'
        UNION
        SELECT rj.jugador_id FROM reserva_jugadores rj JOIN reservas r2 ON rj.reserva_id = r2.id WHERE r2.pack_id = COALESCE(bg.pack_id, r.pack_id) AND r2.fecha = DATE(d.fecha_inicio) AND r2.estado = 'reservado'
    ) t2) as inscritos_count,
    
    COALESCE(p2.capacidad_maxima, p_r.capacidad_maxima, 6) as cantidad_personas,
    COALESCE(p2.nombre, p_r.nombre, 'Clase Grupal') as nombre_pack,
    d.club_id,
    c.nombre as club_nombre,
    c.direccion as club_direccion,
    c.google_maps_link as club_maps,
    u_ent.telefono as entrenador_telefono

FROM disponibilidad_profesor d
INNER JOIN usuarios u_ent ON u_ent.id = d.profesor_id
LEFT JOIN clubes c ON c.id = d.club_id

-- Unión con reservas que EMPIEZAN en este bloque (para etiquetar el inicio)
-- Nota: Si las reservas de Emmanuel y Nelson fueron hechas solo para el 28 de feb, no aparecerán aquí para el 07 de marzo.
LEFT JOIN reservas r ON r.entrenador_id = d.profesor_id 
    AND r.fecha = DATE(d.fecha_inicio)
    AND r.hora_inicio = TIME(d.fecha_inicio)
    AND r.estado = 'reservado'
LEFT JOIN packs p_r ON p_r.id = r.pack_id

-- Unión con CUALQUIER reserva que se solape
LEFT JOIN reservas r_any ON r_any.entrenador_id = d.profesor_id 
    AND r_any.fecha = DATE(d.fecha_inicio)
    AND r_any.hora_inicio < TIME(d.fecha_fin)
    AND r_any.hora_fin > TIME(d.fecha_inicio)
    AND r_any.estado = 'reservado'

-- Unión con bloques_grupo (clases recurrentes confirmadas)
LEFT JOIN bloques_grupo bg ON bg.entrenador_id = d.profesor_id
    AND bg.dia_semana = (DAYOFWEEK(d.fecha_inicio) - 1)
    AND bg.hora_inicio = TIME(d.fecha_inicio)
LEFT JOIN packs p2 ON p2.id = bg.pack_id AND p2.activo = 1

WHERE d.profesor_id = ?
  AND d.activo = 1
  AND DATE(d.fecha_inicio) >= CURDATE()
GROUP BY d.id
ORDER BY d.fecha_inicio ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $entrenador_id);
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $row['tipo'] = $row['tipo_real'];
    $data[] = $row;
}

echo json_encode($data);

$stmt->close();
$conn->close();
