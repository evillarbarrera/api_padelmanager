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

require_once __DIR__ . "/../auth/auth_helper.php";
$userIdFromToken = validateToken();
if (!$userIdFromToken) {
    sendUnauthorized();
}

require_once "../db.php";

$jugador_id = $_GET['jugador_id'] ?? null;

if (!$jugador_id) {
    http_response_code(400);
    echo json_encode(["error" => "jugador_id es obligatorio"]);
    exit;
}

$data = [
    "reservas_individuales" => [],
    "entrenamientos_grupales" => []
];

// 1. Reservas individuales y grupales agendadas en bloques
$sql_reservas = "
SELECT 
    r.id AS reserva_id,
    r.fecha,
    r.hora_inicio,
    r.hora_fin,
    r.estado,
    r.tipo as tipo_reserva,
    r.pack_id,
    (SELECT MAX(pj2.id) 
     FROM pack_jugadores pj2 
     WHERE pj2.pack_id = r.pack_id 
       AND (pj2.jugador_id = rj.jugador_id OR pj2.id IN (SELECT pack_jugadores_id FROM pack_jugadores_adicionales WHERE jugador_id = rj.jugador_id AND estado = 'aceptado'))
    ) AS pack_jugador_id,
    p.nombre AS pack_nombre,
    p.capacidad_minima,
    p.capacidad_maxima,
    p.cantidad_personas,
    p.tipo AS pack_tipo,
    u_e.nombre AS entrenador_nombre,
    u_e.foto_perfil AS entrenador_foto,
    COALESCE(r.tipo, p.tipo, 'individual') AS tipo,
    IFNULL(block_counts.ocupados, 1) as cupos_ocupados,
    p.estado_grupo as pack_estado_grupo,
    c.nombre as club_nombre,
    c.direccion as club_direccion,
    c.google_maps_link as club_maps
FROM reservas r
INNER JOIN reserva_jugadores rj ON rj.reserva_id = r.id
LEFT JOIN packs p ON p.id = r.pack_id
LEFT JOIN clubes c ON c.id = r.club_id
LEFT JOIN (
    SELECT 
        r2.entrenador_id, 
        r2.fecha, 
        r2.hora_inicio, 
        COUNT(rj2.reserva_id) as ocupados 
    FROM reservas r2
    JOIN reserva_jugadores rj2 ON rj2.reserva_id = r2.id
    WHERE r2.estado = 'reservado' AND r2.fecha >= CURDATE()
    GROUP BY r2.entrenador_id, r2.fecha, r2.hora_inicio
) block_counts ON block_counts.entrenador_id = r.entrenador_id 
             AND block_counts.fecha = r.fecha 
             AND block_counts.hora_inicio = r.hora_inicio
LEFT JOIN usuarios u_e ON u_e.id = r.entrenador_id
WHERE rj.jugador_id = ?
  AND r.estado = 'reservado'
  AND (r.fecha > CURDATE() OR (r.fecha = CURDATE() AND r.hora_fin > CURTIME()))
ORDER BY r.fecha ASC, r.hora_inicio ASC
";

$stmt = $conn->prepare($sql_reservas);
$stmt->bind_param("i", $jugador_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    if ($row['tipo'] === 'grupal') {
        $cap_min = (int)($row['capacidad_minima'] > 0 ? $row['capacidad_minima'] : 2);
        $row['capacidad_minima'] = $cap_min;
        if (!isset($row['capacidad_maxima']) || empty($row['capacidad_maxima'])) $row['capacidad_maxima'] = 6;
        $row['estado_grupo'] = ($row['cupos_ocupados'] >= $cap_min) ? 'activo' : 'pendiente';
    }

    $row['invitados'] = [];
    if ($row['pack_jugador_id'] && ($row['cantidad_personas'] ?? 1) > 1) {
        $sqlInv = "
            SELECT u.id, u.nombre, u.usuario, pja.estado, pja.fecha_asignacion 
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
                $row['invitados'][] = $inv;
            }
        }
    }
    $data["reservas_individuales"][] = $row;
}

// 2. Entrenamientos grupales inscritos
$sql_grupales = "
SELECT 
    ig.id AS inscripcion_id,
    p.id AS pack_id,
    p.nombre AS pack_nombre,
    p.categoria,
    p.dia_semana,
    p.hora_inicio,
    p.capacidad_minima,
    p.capacidad_maxima,
    p.cupos_ocupados,
    p.estado_grupo as pack_estado_grupo,
    p.duracion_sesion_min,
    u_e.nombre AS entrenador_nombre,
    u_e.foto_perfil AS entrenador_foto,
    ig.fecha_inscripcion,
    ig.estado,
    'grupal' AS tipo,
    c.nombre as club_nombre,
    c.direccion as club_direccion,
    c.google_maps_link as club_maps
FROM inscripciones_grupales ig
INNER JOIN packs p ON p.id = ig.pack_id
LEFT JOIN usuarios u_e ON u_e.id = p.entrenador_id
LEFT JOIN clubes c ON c.id = p.club_id
WHERE ig.jugador_id = ?
  AND ig.estado = 'activo'
  AND p.activo = 1
  AND p.estado_grupo IN ('pendiente', 'activo')
ORDER BY p.dia_semana ASC, p.hora_inicio ASC
";

$stmt = $conn->prepare($sql_grupales);
$stmt->bind_param("i", $jugador_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $row['duracion_calculada'] = ($row['duracion_sesion_min'] > 0) ? $row['duracion_sesion_min'] : (($row['cupos_ocupados'] >= 5) ? 120 : 60);
    
    $dia_semana_pack = (int)$row['dia_semana'];
    $hoy = new DateTime();
    $dia_actual = (int)$hoy->format('w');
    $dias_diferencia = $dia_semana_pack - $dia_actual;
    if ($dias_diferencia < 0) $dias_diferencia += 7;
    $fecha_calculada = clone $hoy;
    if ($dias_diferencia > 0) {
        $fecha_calculada->modify("+$dias_diferencia days");
    } else if ($dias_diferencia === 0) {
        if ($hoy->format('H:i:s') > $row['hora_inicio']) $fecha_calculada->modify("+7 days");
    }

    $cap_min = (int)($row['capacidad_minima'] > 0 ? $row['capacidad_minima'] : 2);
    $row['estado_grupo'] = ($row['cupos_ocupados'] >= $cap_min) ? 'activo' : 'pendiente';

    for ($i = 0; $i < 4; $i++) {
        $fecha_occ = clone $fecha_calculada;
        if ($i > 0) $fecha_occ->modify("+$i weeks");
        $fecha_occ_str = $fecha_occ->format('Y-m-d');
        
        $is_duplicate = false;
        if (isset($data['reservas_individuales'])) {
            foreach ($data['reservas_individuales'] as $ri) {
                if (isset($ri['pack_id']) && $ri['pack_id'] == $row['pack_id'] && $ri['fecha'] === $fecha_occ_str) {
                    $is_duplicate = true;
                    break;
                }
            }
        }
        
        if (!$is_duplicate) {
            $row_occ = $row;
            $row_occ['fecha'] = $fecha_occ_str;
            $row_occ['id_virtual'] = $row['inscripcion_id'] . '_' . $row_occ['fecha'];
            $data['entrenamientos_grupales'][] = $row_occ;
        }
    }
}

echo json_encode($data);
$conn->close();
?>
