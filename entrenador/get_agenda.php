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
    GROUP_CONCAT(COALESCE(u_j.foto_perfil, u_j.foto, '') SEPARATOR '||') AS jugador_fotos,
    COALESCE(p.capacidad_minima, 2) as capacidad_minima,
    COALESCE(p.capacidad_maxima, 6) as capacidad_maxima,
    MAX(p.duracion_sesion_min) as duracion_original,
    COALESCE(r.tipo, p.tipo, 'individual') as tipo_real,
    MIN(r.estado) as reserva_estado
FROM reservas r
LEFT JOIN reserva_jugadores rj ON rj.reserva_id = r.id
LEFT JOIN usuarios u_j ON u_j.id = rj.jugador_id
LEFT JOIN packs p ON p.id = r.pack_id
WHERE r.entrenador_id = ?
  AND r.fecha >= CURDATE()
  AND r.fecha <= DATE_ADD(CURDATE(), INTERVAL 10 DAY)
  AND r.estado != 'cancelado'
GROUP BY r.fecha, r.hora_inicio, r.entrenador_id, tipo_real, p.id
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
        for($i=0; $i<count($names); $i++) {
            $inscritos_final[] = [
                "id" => trim($ids[$i] ?? ''),
                "nombre" => trim($names[$i]),
                "email" => trim($emails[$i] ?? ''),
                "foto" => trim($fotos[$i] ?? '')
            ];
        }
    }

    $total_inscritos = count($inscritos_final);

    $duracion = $row['duracion_original'] > 0 ? $row['duracion_original'] : (
        $total_inscritos >= 5 ? 120 : ($total_inscritos >= 4 ? 90 : 60)
    );

    $hora_inicio_sec = strtotime($row['hora_inicio']);
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
        "jugador_nombre" => implode(', ', array_column($inscritos_final, 'nombre'))
    ];
}

// 2. Packs recurrentes (Templates)
$sql_grupales = "
SELECT 
    p.id AS pack_id,
    p.nombre AS pack_nombre,
    p.dia_semana,
    p.hora_inicio,
    p.capacidad_minima,
    p.capacidad_maxima,
    p.categoria,
    p.duracion_sesion_min as duracion_original
FROM packs p
WHERE p.entrenador_id = ?
  AND p.tipo = 'grupal'
  AND p.activo = 1
";

$stmt = $conn->prepare($sql_grupales);
$stmt->bind_param("i", $entrenador_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $inscritos = [];
    $count = 0;

    $duracion = $row['duracion_original'] > 0 ? $row['duracion_original'] : 90;

    $hora_inicio_sec = strtotime($row['hora_inicio']);
    $hora_fin = date("H:i:s", $hora_inicio_sec + ($duracion * 60));
    
    $cap_max = ($row['capacidad_maxima'] <= 1) ? 6 : $row['capacidad_maxima'];
    $cap_min = ($row['capacidad_minima'] <= 1) ? 2 : $row['capacidad_minima'];

    $data["packs_grupales"][] = [
        "pack_id" => $row['pack_id'],
        "pack_nombre" => $row['pack_nombre'],
        "dia_semana" => $row['dia_semana'],
        "hora_inicio" => $row['hora_inicio'],
        "hora_fin" => $hora_fin,
        "capacidad_maxima" => $cap_max,
        "cupos_ocupados" => 0,
        "estado_grupo" => "abierto",
        "categoria" => $row['categoria'],
        "duracion_calculada" => $duracion,
        "tipo" => "grupal_template",
        "inscritos" => [],
        "jugador_nombre" => ""
    ];
}

echo json_encode($data);
