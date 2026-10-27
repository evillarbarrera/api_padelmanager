<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

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
    sendUnauthorized();
}

require_once "../db.php";

$entrenador_id = $_GET['entrenador_id'] ?? null;

if (!$entrenador_id) {
    http_response_code(400);
    echo json_encode(["error" => "entrenador_id es obligatorio"]);
    exit;
}

$data = [
    "reservas_tradicionales" => [],
    "packs_grupales" => []
];

// 1. Reservas agendadas (Individuales y Grupales con fecha específica)
$sql_reservas = "
SELECT 
    MIN(r.id) AS reserva_id,
    r.fecha,
    r.hora_inicio,
    p.id as pack_id,
    p.nombre AS pack_nombre,
    p.categoria,
    GROUP_CONCAT(COALESCE(u_j.id, '') SEPARATOR '||') AS jugador_ids,
    GROUP_CONCAT(COALESCE(u_j.nombre, '') SEPARATOR '||') AS jugador_nombre,
    GROUP_CONCAT(COALESCE(u_j.usuario, '') SEPARATOR '||') AS jugador_emails,
    GROUP_CONCAT(COALESCE(NULLIF(u_j.foto_perfil, ''), NULLIF(u_j.foto, ''), '') SEPARATOR '||') AS jugador_fotos,
    COALESCE(p.capacidad_minima, 2) as capacidad_minima,
    COALESCE(p.capacidad_maxima, 6) as capacidad_maxima,
    MAX(p.duracion_sesion_min) as duracion_original,
    COALESCE(r.tipo, p.tipo, 'individual') as tipo_real,
    MIN(r.estado) as reserva_estado,
    COALESCE(MIN(r.club_id), MIN(p.club_id), MIN(can.club_id), 0) as club_id,
    COALESCE(MIN(c.nombre), MIN(p_c.nombre), MIN(can_club.nombre), '') as club_nombre,
    COALESCE(MIN(rc.cancha_id), MIN(r.cancha_id)) as cancha_id,
    COALESCE(MIN(can.nombre), '') as cancha_nombre,
    COALESCE(MIN(c.reservas_activas), MIN(p_c.reservas_activas), 1) as club_reservas_activas,
    (
        SELECT COUNT(*) 
        FROM canchas can_count 
        WHERE can_count.club_id = COALESCE(MIN(r.club_id), MIN(p.club_id), MIN(can.club_id), 0)
          AND (can_count.activa = 1 OR can_count.activa IS NULL)
    ) as club_total_canchas,
    MIN(r.malla_id) as malla_id,
    MIN(r.clase_id) as clase_id,
    MIN(r.clase_titulo) as clase_titulo,
    MIN(m.nombre) as malla_nombre,
    MIN(cm.objetivo) as clase_objetivo,
    MIN(cm.calentamiento) as clase_calentamiento,
    MIN(cm.drills) as clase_drills,
    MIN(cm.juego) as clase_juego,
    MIN(cm.recursos) as clase_recursos
FROM reservas r
LEFT JOIN reserva_jugadores rj ON rj.reserva_id = r.id
LEFT JOIN usuarios u_j ON u_j.id = rj.jugador_id
LEFT JOIN packs p ON p.id = r.pack_id
LEFT JOIN clubes c ON c.id = r.club_id
LEFT JOIN clubes p_c ON p_c.id = p.club_id
LEFT JOIN reservas_cancha rc ON (
    (rc.reserva_entrenador_id IS NOT NULL AND rc.reserva_entrenador_id = r.id)
    OR (
        (rc.reserva_entrenador_id IS NULL OR rc.reserva_entrenador_id = 0)
        AND rc.entrenador_id = r.entrenador_id
        AND rc.fecha = r.fecha
        AND rc.hora_inicio = r.hora_inicio
        AND rc.tipo_reserva = 'entrenamiento'
    )
) AND LOWER(TRIM(rc.estado)) NOT IN ('cancelada', 'cancelado', 'anulada', 'anulado', 'rechazada', 'eliminada')
LEFT JOIN canchas can ON can.id = COALESCE(rc.cancha_id, r.cancha_id)
LEFT JOIN clubes can_club ON can_club.id = can.club_id
LEFT JOIN mallas m ON m.id = r.malla_id
LEFT JOIN clases_malla cm ON cm.id = r.clase_id
WHERE r.entrenador_id = ?
  AND r.fecha >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
  AND r.fecha <= DATE_ADD(CURDATE(), INTERVAL 35 DAY)
  AND r.estado != 'cancelado'
GROUP BY r.fecha, r.hora_inicio, r.entrenador_id, COALESCE(r.tipo, p.tipo, 'individual'), p.id
ORDER BY r.fecha ASC, r.hora_inicio ASC
";

$stmt = $conn->prepare($sql_reservas);
$stmt->bind_param("i", $entrenador_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $inscritos_final = [];
    if ($row['jugador_nombre']) {
        $names = explode('||', $row['jugador_nombre']);
        $emails = explode('||', $row['jugador_emails']);
        $fotos = explode('||', $row['jugador_fotos']);
        $ids = explode('||', $row['jugador_ids']);
        for ($i = 0; $i < count($names); $i++) {
            $f = trim($fotos[$i] ?? '');
            $inscritos_final[] = [
                "id" => trim($ids[$i] ?? ''),
                "nombre" => trim($names[$i]),
                "email" => trim($emails[$i] ?? ''),
                "foto" => $f,
                "foto_perfil" => $f
            ];
        }
    }

    $total_inscritos = count($inscritos_final);

    $duracion = $row['duracion_original'] > 0 ? $row['duracion_original'] : (
        $total_inscritos >= 5 ? 120 : ($total_inscritos >= 4 ? 90 : 60)
    );

    $hora_inicio_val = $row['hora_inicio'] ?? '08:00:00';
    $hora_inicio_sec = strtotime($hora_inicio_val);
    $hora_fin = date("H:i:s", $hora_inicio_sec + ($duracion * 60));

    // Forzar capacidad mínima coherente para grupos
    $cap_max = $row['capacidad_maxima'];
    if (($row['tipo_real'] === 'grupal' || $row['tipo_real'] === 'pack_grupal') && $cap_max <= 1) {
        $cap_max = 6;
    }

    $estado_final = $row['reserva_estado'];
    if ($row['tipo_real'] === 'grupal' || $row['tipo_real'] === 'pack_grupal') {
        $cap_min = ($row['capacidad_minima'] <= 1) ? 2 : $row['capacidad_minima'];
        $estado_final = ($total_inscritos >= $cap_min) ? 'activo' : 'pendiente';
    }

    $club_id = intval($row['club_id'] ?? 0);
    $cancha_id = intval($row['cancha_id'] ?? 0);
    $cancha_nombre = trim($row['cancha_nombre'] ?? '');
    $club_total_canchas = intval($row['club_total_canchas'] ?? 0);
    $club_reservas_activas = intval($row['club_reservas_activas'] ?? 1);

    // Auto-curar sincronización en reservas si reservas_cancha tenía una pista actualizada
    if ($cancha_id > 0 && !empty($row['reserva_id'])) {
        $resIdCurar = intval($row['reserva_id']);
        @$conn->query("UPDATE reservas SET cancha_id = $cancha_id WHERE id = $resIdCurar AND (cancha_id IS NULL OR cancha_id != $cancha_id)");
    }
    
    // El club tiene disponible la opción de reserva de pistas si tiene canchas activas y reservas activas
    $club_permite_canchas = ($club_id > 0 && $club_total_canchas > 0 && $club_reservas_activas === 1);
    // Requiere cancha si el club ofrece reservas de pistas pero la reserva aún no tiene una cancha asignada
    $requiere_cancha = ($club_permite_canchas && $cancha_id <= 0);

    $data["reservas_tradicionales"][] = [
        "reserva_id" => $row['reserva_id'],
        "fecha" => $row['fecha'],
        "hora_inicio" => $row['hora_inicio'],
        "hora_fin" => $hora_fin,
        "estado" => $estado_final,
        "estado_grupo" => ($row['tipo_real'] === 'grupal' || $row['tipo_real'] === 'pack_grupal' ? $estado_final : null),
        "pack_nombre" => $row['pack_nombre'],
        "categoria" => $row['categoria'],
        "inscritos" => $inscritos_final,
        "cupos_ocupados" => $total_inscritos,
        "capacidad_maxima" => $cap_max,
        "duracion_calculada" => $duracion,
        "tipo" => ($row['tipo_real'] === 'grupal' ? 'pack_grupal' : 'reserva_individual'),
        "jugador_nombre" => implode(', ', array_column($inscritos_final, 'nombre')),
        "club_id" => $club_id,
        "club_nombre" => $row['club_nombre'],
        "cancha_id" => ($cancha_id > 0 ? $cancha_id : null),
        "cancha_nombre" => $cancha_nombre,
        "club_permite_canchas" => $club_permite_canchas,
        "requiere_cancha" => $requiere_cancha,
        "malla_id" => $row['malla_id'],
        "clase_id" => $row['clase_id'],
        "clase_titulo" => $row['clase_titulo'],
        "malla_nombre" => $row['malla_nombre'] ?? '',
        "clase_objetivo" => $row['clase_objetivo'] ?? '',
        "clase_calentamiento" => $row['clase_calentamiento'] ?? '',
        "clase_drills" => $row['clase_drills'] ?? '',
        "clase_juego" => $row['clase_juego'] ?? '',
        "clase_recursos" => $row['clase_recursos'] ?? ''
    ];
}

// 2. Packs recurrentes (Templates)
$sql_grupales = "
SELECT 
    p.id AS pack_id,
    p.nombre AS pack_nombre,
    COALESCE(WEEKDAY(p.fecha), p.dia_semana) as dia_semana,
    p.hora_inicio,
    p.capacidad_minima,
    p.capacidad_maxima,
    p.categoria,
    p.fecha,
    p.permite_inscripcion,
    p.duracion_sesion_min as duracion_original,
    COALESCE(c.nombre, '') as club_nombre
FROM packs p
LEFT JOIN clubes c ON c.id = p.club_id
WHERE p.entrenador_id = ?
  AND p.tipo = 'grupal'
  AND p.activo = 1
  AND p.fecha IS NOT NULL
  AND p.fecha >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
  AND p.fecha <= DATE_ADD(CURDATE(), INTERVAL 35 DAY)
";

$stmt = $conn->prepare($sql_grupales);
$stmt->bind_param("i", $entrenador_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    // Si tiene fecha, solo mostrar si es relevante para el rango de la agenda
    if ($row['fecha']) {
        $fecha_ts = strtotime($row['fecha']);
        $start_range = strtotime("-60 days", strtotime("today"));
        $end_range = strtotime("+35 days", strtotime("today"));
        
        if ($fecha_ts < $start_range || $fecha_ts > $end_range) {
            continue;
        }
    }

    $duracion = $row['duracion_original'] > 0 ? $row['duracion_original'] : 90;

    $hora_inicio_val = $row['hora_inicio'] ?? '08:00:00';
    $hora_inicio_sec = strtotime($hora_inicio_val);
    $hora_fin = date("H:i:s", $hora_inicio_sec + ($duracion * 60));

    $cap_max = ($row['capacidad_maxima'] <= 1) ? 6 : $row['capacidad_maxima'];
    $cap_min = ($row['capacidad_minima'] <= 1) ? 2 : $row['capacidad_minima'];

    // Obtener inscritos actuales para este pack si es por fecha
    $inscritos = [];
    if ($row['fecha']) {
        $sql_ig = "SELECT u.id, u.nombre, u.usuario as email, 
                          COALESCE(NULLIF(u.foto_perfil, ''), NULLIF(u.foto, ''), '') as foto,
                          COALESCE(NULLIF(u.foto_perfil, ''), NULLIF(u.foto, ''), '') as foto_perfil
                   FROM (
                       SELECT jugador_id FROM inscripciones_grupales WHERE pack_id = ? AND estado = 'activo'
                       UNION
                       SELECT rj.jugador_id FROM reserva_jugadores rj JOIN reservas r2 ON rj.reserva_id = r2.id WHERE r2.pack_id = ? AND r2.fecha = ? AND r2.estado = 'reservado'
                   ) t
                   JOIN usuarios u ON u.id = t.jugador_id";
        $stmt_ig = $conn->prepare($sql_ig);
        $stmt_ig->bind_param("iis", $row['pack_id'], $row['pack_id'], $row['fecha']);
        $stmt_ig->execute();
        $res_ig = $stmt_ig->get_result();
        while($row_ig = $res_ig->fetch_assoc()) {
            $inscritos[] = $row_ig;
        }
    } else {
        // Para plantillas sin fecha, contar solo inscripciones fijas
        $sql_ig = "SELECT u.id, u.nombre, u.usuario as email, 
                          COALESCE(NULLIF(u.foto_perfil, ''), NULLIF(u.foto, ''), '') as foto,
                          COALESCE(NULLIF(u.foto_perfil, ''), NULLIF(u.foto, ''), '') as foto_perfil
                   FROM inscripciones_grupales ig 
                   JOIN usuarios u ON u.id = ig.jugador_id 
                   WHERE ig.pack_id = ? AND ig.estado = 'activo'";
        $stmt_ig = $conn->prepare($sql_ig);
        $stmt_ig->bind_param("i", $row['pack_id']);
        $stmt_ig->execute();
        $res_ig = $stmt_ig->get_result();
        while($row_ig = $res_ig->fetch_assoc()) {
            $inscritos[] = $row_ig;
        }
    }

    $data["packs_grupales"][] = [
        "pack_id" => $row['pack_id'],
        "pack_nombre" => $row['pack_nombre'],
        "dia_semana" => $row['dia_semana'],
        "fecha" => $row['fecha'],
        "hora_inicio" => $hora_inicio_val,
        "hora_fin" => $hora_fin,
        "capacidad_maxima" => $cap_max,
        "cupos_ocupados" => count($inscritos),
        "estado_grupo" => ($row['fecha'] ? (count($inscritos) >= $cap_min ? 'activo' : 'pendiente') : "abierto"),
        "categoria" => $row['categoria'],
        "duracion_calculada" => $duracion,
        "tipo" => ($row['fecha'] ? "grupal_fecha" : "grupal_template"),
        "inscritos" => $inscritos,
        "jugador_nombre" => implode(', ', array_column($inscritos, 'nombre')),
        "club_nombre" => $row['club_nombre']
    ];
}

echo json_encode($data);
