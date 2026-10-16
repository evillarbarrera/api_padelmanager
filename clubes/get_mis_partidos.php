<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";
require_once "../auth/auth_helper.php";

function ensureCanchaColumns($conn) {
    $cols = [
        'categoria' => "VARCHAR(50) NOT NULL DEFAULT 'cancha_padel'",
        'icono' => "VARCHAR(50) DEFAULT '🎾'",
        'descripcion' => "TEXT NULL",
        'capacidad' => "INT DEFAULT 4"
    ];
    foreach ($cols as $col => $definition) {
        $check = $conn->query("SHOW COLUMNS FROM canchas LIKE '$col'");
        if ($check && $check->num_rows == 0) {
            @$conn->query("ALTER TABLE canchas ADD COLUMN $col $definition");
        }
    }
}
ensureCanchaColumns($conn);

$userId = validateToken();
if (!$userId && !empty($_GET['usuario_id'])) {
    $userId = (int)$_GET['usuario_id'];
}

if (!$userId) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$club_id = $_GET['club_id'] ?? 0;
$where = "(r.usuario_id = ? OR r.jugador2_id = ? OR r.jugador3_id = ? OR r.jugador4_id = ?)";
if ($club_id) {
    $where .= " AND cl.id = " . (int)$club_id;
}

$sql = "SELECT r.*, 
               c.nombre as cancha_nombre, 
               c.categoria as cancha_categoria,
               c.icono as cancha_icono,
               c.tipo as cancha_tipo,
               c.superficie as cancha_superficie,
               c.capacidad as cancha_capacidad,
               c.descripcion as cancha_descripcion,
               cl.nombre as club_nombre,
               cl.logo as club_logo, 
               cl.direccion as club_direccion,
               u1.nombre as jugador1_nombre,
               u1.foto_perfil as jugador1_foto,
               u2.nombre as jugador2_nombre,
               u2.foto_perfil as jugador2_foto,
               u3.nombre as jugador3_nombre,
               u3.foto_perfil as jugador3_foto,
               u4.nombre as jugador4_nombre,
               u4.foto_perfil as jugador4_foto,
               sw.id as sw_sesion_id,
               sw.total_golpes as sw_total_golpes,
               sw.smash_count as sw_smash_count,
               sw.bandeja_count as sw_bandeja_count,
               sw.vibora_count as sw_vibora_count,
               sw.velocidad_max_kmh as sw_velocidad_max_kmh,
               sw.velocidad_media_kmh as sw_velocidad_media_kmh,
               sw.calorias_quemadas as sw_calorias,
               sw.fc_promedio as sw_fc_promedio,
               sw.fc_maxima as sw_fc_maxima,
               sw.marcador_final_t1 as sw_marcador_t1,
               sw.marcador_final_t2 as sw_marcador_t2,
               sw.equipo_ganador as sw_equipo_ganador,
               sw.dispositivo_modelo as sw_dispositivo
        FROM reservas_cancha r
        JOIN canchas c ON r.cancha_id = c.id
        JOIN clubes cl ON c.club_id = cl.id
        LEFT JOIN usuarios u1 ON r.usuario_id = u1.id
        LEFT JOIN usuarios u2 ON r.jugador2_id = u2.id
        LEFT JOIN usuarios u3 ON r.jugador3_id = u3.id
        LEFT JOIN usuarios u4 ON r.jugador4_id = u4.id
        LEFT JOIN smartwatch_sesiones sw ON sw.reserva_id = r.id
        WHERE $where
        ORDER BY r.fecha DESC, r.hora_inicio DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iiii", $userId, $userId, $userId, $userId);
$stmt->execute();
$result = $stmt->get_result();

$partidos = [];
while ($row = $result->fetch_assoc()) {
    // Determinar si ya pasó (partido jugado) o es futuro
    $ahora = new DateTime();
    try {
        if (!empty($row['hora_fin']) && substr($row['hora_fin'], 0, 2) === '24') {
            $fecha_partido = (new DateTime($row['fecha'] . ' 00:00:00'))->modify('+1 day');
        } else {
            $hFinVal = !empty($row['hora_fin']) ? $row['hora_fin'] : '23:59:59';
            $fecha_partido = new DateTime($row['fecha'] . ' ' . $hFinVal);
        }
        $row['jugado'] = ($fecha_partido < $ahora);
    } catch (Throwable $eDate) {
        $row['jugado'] = false;
    }

    // Si tiene sesión de Smartwatch asociada
    if (!empty($row['sw_sesion_id'])) {
        $row['smartwatch_data'] = [
            "sesion_id" => (int)$row['sw_sesion_id'],
            "total_golpes" => (int)$row['sw_total_golpes'],
            "smash_count" => (int)$row['sw_smash_count'],
            "bandeja_count" => (int)$row['sw_bandeja_count'],
            "vibora_count" => (int)$row['sw_vibora_count'],
            "velocidad_max_kmh" => (float)$row['sw_velocidad_max_kmh'],
            "velocidad_media_kmh" => (float)$row['sw_velocidad_media_kmh'],
            "calorias" => (float)$row['sw_calorias'],
            "fc_promedio" => (int)$row['sw_fc_promedio'],
            "fc_maxima" => (int)$row['sw_fc_maxima'],
            "marcador_t1" => $row['sw_marcador_t1'],
            "marcador_t2" => $row['sw_marcador_t2'],
            "equipo_ganador" => !empty($row['sw_equipo_ganador']) ? (int)$row['sw_equipo_ganador'] : null,
            "dispositivo" => $row['sw_dispositivo'] ?? 'Apple Watch'
        ];

        // Rellenar marcador si no existía manualmente
        if (empty($row['marcador']) && !empty($row['sw_marcador_t1'])) {
            $row['marcador'] = $row['sw_marcador_t1'] . ' - ' . $row['sw_marcador_t2'];
            $row['resultado_registrado'] = 1;
        }
        if (empty($row['id_ganador']) && !empty($row['sw_equipo_ganador'])) {
            $row['id_ganador'] = (int)$row['sw_equipo_ganador'];
        }
    }
    
    $partidos[] = $row;
}

echo json_encode($partidos);
?>
