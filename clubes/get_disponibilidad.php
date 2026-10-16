<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
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

$cancha_id = $_GET['cancha_id'] ?? 0;
$club_id   = $_GET['club_id'] ?? 0;
$fecha     = $_GET['fecha'] ?? date('Y-m-d');

if (!$cancha_id && !$club_id) {
    http_response_code(400);
    echo json_encode(["error" => "cancha_id o club_id es requerido"]);
    exit;
}

// SILENT SCHEMA FIX
function ensureCanchaColumns($conn) {
    $cols = [
        'categoria' => "VARCHAR(50) DEFAULT 'cancha_padel'",
        'icono' => "VARCHAR(50) DEFAULT '🎾'",
        'descripcion' => "TEXT NULL",
        'capacidad' => "INT DEFAULT 4",
        'precio_60_alto' => "DECIMAL(10,2) DEFAULT 0.00",
        'precio_90_alto' => "DECIMAL(10,2) DEFAULT 0.00",
        'precio_120_alto' => "DECIMAL(10,2) DEFAULT 0.00",
        'hora_inicio_bajo' => "TIME DEFAULT '07:00:00'",
        'hora_fin_bajo' => "TIME DEFAULT '18:00:00'",
        'hora_inicio_alto' => "TIME DEFAULT '18:00:00'",
        'hora_fin_alto' => "TIME DEFAULT '24:00:00'"
    ];
    foreach ($cols as $col => $def) {
        $check = $conn->query("SHOW COLUMNS FROM canchas LIKE '$col'");
        if ($check && $check->num_rows == 0) {
            $conn->query("ALTER TABLE canchas ADD `$col` $def");
        }
    }
}
// Limpiar reservas huérfanas de liga o torneos desincronizados antes de calcular disponibilidad
@$conn->query("DELETE r FROM reservas_cancha r 
               LEFT JOIN liga_partidos lp ON r.liga_partido_id = lp.id 
               WHERE r.liga_partido_id IS NOT NULL 
                 AND r.liga_partido_id > 0
                 AND (lp.id IS NULL OR lp.fecha_hora IS NULL OR lp.fecha_hora LIKE '0000%' OR lp.cancha_id IS NULL)");

@$conn->query("DELETE r FROM reservas_cancha r 
               LEFT JOIN torneos_americanos ta ON r.torneo_id = ta.id 
               WHERE r.torneo_id IS NOT NULL 
                 AND r.torneo_id > 0 
                 AND (ta.id IS NULL OR ta.estado = 'Cerrado' OR r.fecha != ta.fecha)");

function getFreeDuration($conn, $cancha, $fecha, $h_inicio, $fin_ts) {
    $cid = $cancha['id'];
    $t = strtotime("$fecha $h_inicio");
    
    // Check if the court has prices configured for 60, 90, 120 min
    $has_120 = (float)($cancha['precio_120'] ?? 0) > 0 || (float)($cancha['precio_120_alto'] ?? 0) > 0;
    $has_90 = (float)($cancha['precio_90'] ?? 0) > 0 || (float)($cancha['precio_90_alto'] ?? 0) > 0;
    $has_60 = (float)($cancha['precio_60'] ?? 0) > 0 || (float)($cancha['precio_60_alto'] ?? 0) > 0;

    if (!$has_120 && !$has_90 && !$has_60) {
        $has_90 = true;
        $has_60 = true;
    }

    $is_120_ok = $has_120;
    $is_90_ok = $has_90;
    $is_60_ok = $has_60;

    // 120 min
    if ($fin_ts - $t < 120 * 60) {
        $is_120_ok = false;
    }
    // 90 min
    if ($fin_ts - $t < 90 * 60) {
        $is_90_ok = false;
    }
    // 60 min
    if ($fin_ts - $t < 60 * 60) {
        $is_60_ok = false;
    }

    $collisionSql = "SELECT id FROM reservas_cancha 
                     WHERE cancha_id = ? AND fecha = ? 
                       AND LOWER(estado) NOT IN ('cancelada', 'cancelado', 'anulada', 'anulado', 'rechazada', 'rechazado', 'eliminada', 'eliminado')
                       AND TIME_TO_SEC(hora_inicio) < ? 
                       AND (CASE WHEN hora_fin IN ('00:00:00', '00:00', '24:00', '24:00:00', '23:59', '23:59:00', '23:59:59') OR hora_fin <= hora_inicio THEN 86400 ELSE TIME_TO_SEC(hora_fin) END) > ?";

    $h_ini_clean = trim($h_inicio);
    if (strlen($h_ini_clean) === 5) {
        $h_ini_clean .= ':00';
    }
    $start_sec = (intval(substr($h_ini_clean, 0, 2)) * 3600) + (intval(substr($h_ini_clean, 3, 2)) * 60) + intval(substr($h_ini_clean, 6, 2));

    if ($is_120_ok) {
        $end_sec_120 = min(86400, $start_sec + 7200);
        $stmt = $conn->prepare($collisionSql);
        $stmt->bind_param("isii", $cid, $fecha, $end_sec_120, $start_sec);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $is_120_ok = false;
        }
        $stmt->close();
    }

    if ($is_90_ok) {
        $end_sec_90 = min(86400, $start_sec + 5400);
        $stmt = $conn->prepare($collisionSql);
        $stmt->bind_param("isii", $cid, $fecha, $end_sec_90, $start_sec);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $is_90_ok = false;
        }
        $stmt->close();
    }

    if ($is_60_ok) {
        $end_sec_60 = min(86400, $start_sec + 3600);
        $stmt = $conn->prepare($collisionSql);
        $stmt->bind_param("isii", $cid, $fecha, $end_sec_60, $start_sec);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $is_60_ok = false;
        }
        $stmt->close();
    }

    if ($is_120_ok) return 120;
    if ($is_90_ok) return 90;
    if ($is_60_ok) return 60;
    return 0;
}

$response = [];

// 1. Obtener las canchas involucradas
$canchas_ids = [];
if ($club_id) {
    $sqlC = "SELECT id, nombre, tipo, superficie, categoria, icono, descripcion, capacidad, precio_60, precio_90, precio_120, precio_60_alto, precio_90_alto, precio_120_alto, hora_inicio_bajo, hora_fin_bajo, hora_inicio_alto, hora_fin_alto, horarios_adicionales FROM canchas WHERE club_id = ? AND activa = 1 ORDER BY id ASC";
    $stmtC = $conn->prepare($sqlC);
    $stmtC->bind_param("i", $club_id);
    $stmtC->execute();
    $resC = $stmtC->get_result();
    while($c = $resC->fetch_assoc()) {
        $canchas_ids[] = $c;
    }
} else {
    $sqlC = "SELECT id, nombre, tipo, superficie, categoria, icono, descripcion, capacidad, precio_60, precio_90, precio_120, precio_60_alto, precio_90_alto, precio_120_alto, hora_inicio_bajo, hora_fin_bajo, hora_inicio_alto, hora_fin_alto, horarios_adicionales FROM canchas WHERE id = ? AND activa = 1 ORDER BY id ASC";
    $stmtC = $conn->prepare($sqlC);
    $stmtC->bind_param("i", $cancha_id);
    $stmtC->execute();
    $resC = $stmtC->get_result();
    if($c = $resC->fetch_assoc()) {
        $canchas_ids[] = $c;
    }
}

$horarios_mapeados = []; // [ '09:00:00' => [ {cancha_id, cancha_nombre, disponible} ] ]

foreach ($canchas_ids as $cancha) {
    $cid = $cancha['id'];
    $cnombre = $cancha['nombre'];

    $h_inicio_bajo_str = !empty($cancha['hora_inicio_bajo']) ? substr($cancha['hora_inicio_bajo'], 0, 5) : '07:00';
    $h_fin_bajo_str = !empty($cancha['hora_fin_bajo']) ? substr($cancha['hora_fin_bajo'], 0, 5) : '18:00';
    $h_inicio_alto_str = !empty($cancha['hora_inicio_alto']) ? substr($cancha['hora_inicio_alto'], 0, 5) : '18:00';
    $h_fin_alto_str = !empty($cancha['hora_fin_alto']) ? substr($cancha['hora_fin_alto'], 0, 5) : '24:00';
    if ($h_fin_alto_str === '00:00' || $h_fin_alto_str === '23:30' || $h_fin_alto_str === '23:59') {
        $h_fin_alto_str = '24:00';
    }

    $tramos_json = [];
    if (!empty($cancha['horarios_adicionales'])) {
        $parsed = json_decode($cancha['horarios_adicionales'], true);
        if (is_array($parsed) && count($parsed) > 0) {
            $tramos_json = $parsed;
        }
    }

    $dia_semana = date('w', strtotime($fecha));
    $sqlConfig = "SELECT * FROM cancha_horarios_config WHERE cancha_id = ? AND dia_semana = ?";
    $stmtConfig = $conn->prepare($sqlConfig);
    $stmtConfig->bind_param("ii", $cid, $dia_semana);
    $stmtConfig->execute();
    $resConfig = $stmtConfig->get_result();

    if ($resConfig->num_rows == 0) {
        $inicio = strtotime("$fecha 07:00:00");
        $fin = strtotime("$fecha 00:00:00 +1 day");
        $bloque = 30 * 60; // 30 min default
        for ($t = $inicio; $t < $fin; $t += $bloque) {
            $h_inicio = date('H:i:s', $t);
            $h_fin_slot = ($t + $bloque >= $fin) ? '24:00:00' : date('H:i:s', $t + $bloque);
            $h_inicio_short = substr($h_inicio, 0, 5);

            $matched_tramo = null;
            if (count($tramos_json) > 0) {
                foreach ($tramos_json as $idx => $tr) {
                    $s_tr = substr($tr['hora_inicio'] ?? '', 0, 5);
                    $e_tr = substr($tr['hora_fin'] ?? '', 0, 5);
                    $e_tr_cmp = ($e_tr === '00:00' || $e_tr === '24:00' || $e_tr === '23:59' || $e_tr === '23:30') ? '24:00' : $e_tr;
                    if ($h_inicio_short >= $s_tr && $h_inicio_short < $e_tr_cmp) {
                        $matched_tramo = $tr;
                        $matched_tramo['_is_peak'] = ($idx > 0);
                        break;
                    }
                }
            }

            if ($matched_tramo) {
                $p60 = (float)($matched_tramo['precio_60'] ?? 0);
                $p90 = (float)($matched_tramo['precio_90'] ?? 0);
                $p120 = (float)($matched_tramo['precio_120'] ?? 0);
                $es_horario_alto = $matched_tramo['_is_peak'];
                $nombre_tarifa = $matched_tramo['nombre'] ?? ($es_horario_alto ? '⚡ Horario Alto' : '🌿 Horario Bajo');
            } else {
                $h_fin_alto_cmp = ($h_fin_alto_str === '00:00' || $h_fin_alto_str === '24:00' || $h_fin_alto_str === '23:59' || $h_fin_alto_str === '23:30') ? '24:00' : $h_fin_alto_str;
                $es_horario_alto = ($h_inicio_short >= $h_inicio_alto_str && $h_inicio_short < $h_fin_alto_cmp);
                $p60 = $es_horario_alto ? (float)$cancha['precio_60_alto'] : (float)$cancha['precio_60'];
                $p90 = $es_horario_alto ? (float)$cancha['precio_90_alto'] : (float)$cancha['precio_90'];
                $p120 = $es_horario_alto ? (float)$cancha['precio_120_alto'] : (float)$cancha['precio_120'];
                $nombre_tarifa = $es_horario_alto ? '⚡ Horario Alto' : '🌿 Horario Bajo';
            }

            $duracion_libre = getFreeDuration($conn, $cancha, $fecha, $h_inicio, $fin);
            $is_reserved = ($duracion_libre == 0);

            $display_name = $cnombre;
            $p60_slot = $p60;
            $p90_slot = $p90;
            $p120_slot = $p120;

            if ($duracion_libre == 60) {
                $display_name .= " (Solo 60 min)";
                $p90_slot = 0;
                $p120_slot = 0;
            } else if ($duracion_libre == 90) {
                $display_name .= " (Máx 90 min)";
                $p120_slot = 0;
            }

            $horarios_mapeados[$h_inicio][] = [
                "cancha_id" => $cid,
                "cancha_nombre" => $display_name,
                "categoria" => $cancha['categoria'] ?? 'cancha_padel',
                "icono" => $cancha['icono'] ?? '🎾',
                "descripcion" => $cancha['descripcion'] ?? '',
                "capacidad" => intval($cancha['capacidad'] ?? 4),
                "tipo" => $cancha['tipo'] ?? 'Outdoor',
                "superficie" => $cancha['superficie'] ?? 'Césped Sintético',
                "disponible" => !$is_reserved,
                "hora_fin" => $h_fin_slot,
                "precio_60" => (float)$cancha['precio_60'],
                "precio_90" => (float)$cancha['precio_90'],
                "precio_120" => (float)$cancha['precio_120'],
                "precio_60_alto" => (float)$cancha['precio_60_alto'],
                "precio_90_alto" => (float)$cancha['precio_90_alto'],
                "precio_120_alto" => (float)$cancha['precio_120_alto'],
                "precio_slot_60" => $p60_slot,
                "precio_slot_90" => $p90_slot,
                "precio_slot_120" => $p120_slot,
                "nombre_tarifa" => $nombre_tarifa,
                "hora_inicio_bajo" => $h_inicio_bajo_str,
                "hora_fin_bajo" => $h_fin_bajo_str,
                "hora_inicio_alto" => $h_inicio_alto_str,
                "hora_fin_alto" => $h_fin_alto_str,
                "es_horario_alto" => $es_horario_alto
            ];
        }
    } else {
        while ($config = $resConfig->fetch_assoc()) {
            $inicio = strtotime("$fecha " . $config['hora_inicio']);
            $fin_str = $config['hora_fin'];
            if ($fin_str === '00:00:00' || $fin_str === '24:00:00' || $fin_str === '23:59:59' || $fin_str === '23:30:00') {
                $fin = strtotime("$fecha 00:00:00 +1 day");
            } else {
                $fin = strtotime("$fecha $fin_str");
            }
            $bloque = $config['duracion_bloque'] * 60;
            for ($t = $inicio; $t < $fin; $t += $bloque) {
                $h_inicio = date('H:i:s', $t);
                $h_fin = ($t + $bloque >= $fin) ? '24:00:00' : date('H:i:s', $t + $bloque);
                $h_inicio_short = substr($h_inicio, 0, 5);

                $matched_tramo = null;
                if (count($tramos_json) > 0) {
                    foreach ($tramos_json as $idx => $tr) {
                        $s_tr = substr($tr['hora_inicio'] ?? '', 0, 5);
                        $e_tr = substr($tr['hora_fin'] ?? '', 0, 5);
                        $e_tr_cmp = ($e_tr === '00:00' || $e_tr === '24:00' || $e_tr === '23:59' || $e_tr === '23:30') ? '24:00' : $e_tr;
                        if ($h_inicio_short >= $s_tr && $h_inicio_short < $e_tr_cmp) {
                            $matched_tramo = $tr;
                            $matched_tramo['_is_peak'] = ($idx > 0);
                            break;
                        }
                    }
                }

                if ($matched_tramo) {
                    $p60 = (float)($matched_tramo['precio_60'] ?? 0);
                    $p90 = (float)($matched_tramo['precio_90'] ?? 0);
                    $p120 = (float)($matched_tramo['precio_120'] ?? 0);
                    $es_horario_alto = $matched_tramo['_is_peak'];
                    $nombre_tarifa = $matched_tramo['nombre'] ?? ($es_horario_alto ? '⚡ Horario Alto' : '🌿 Horario Bajo');
                } else {
                    $h_fin_alto_cmp = ($h_fin_alto_str === '00:00' || $h_fin_alto_str === '24:00' || $h_fin_alto_str === '23:59' || $h_fin_alto_str === '23:30') ? '24:00' : $h_fin_alto_str;
                    $es_horario_alto = ($h_inicio_short >= $h_inicio_alto_str && $h_inicio_short < $h_fin_alto_cmp);
                    $p60 = $es_horario_alto ? (float)$cancha['precio_60_alto'] : (float)$cancha['precio_60'];
                    $p90 = $es_horario_alto ? (float)$cancha['precio_90_alto'] : (float)$cancha['precio_90'];
                    $p120 = $es_horario_alto ? (float)$cancha['precio_120_alto'] : (float)$cancha['precio_120'];
                    $nombre_tarifa = $es_horario_alto ? '⚡ Horario Alto' : '🌿 Horario Bajo';
                }

                $duracion_libre = getFreeDuration($conn, $cancha, $fecha, $h_inicio, $fin);
                $is_reserved = ($duracion_libre == 0);

                $display_name = $cnombre;
                $p60_slot = $p60;
                $p90_slot = $p90;
                $p120_slot = $p120;

                if ($duracion_libre == 60) {
                    $display_name .= " (Solo 60 min)";
                    $p90_slot = 0;
                    $p120_slot = 0;
                } else if ($duracion_libre == 90) {
                    $display_name .= " (Máx 90 min)";
                    $p120_slot = 0;
                }

                $horarios_mapeados[$h_inicio][] = [
                    "cancha_id" => $cid,
                    "cancha_nombre" => $display_name,
                    "categoria" => $cancha['categoria'] ?? 'cancha_padel',
                    "icono" => $cancha['icono'] ?? '🎾',
                    "descripcion" => $cancha['descripcion'] ?? '',
                    "capacidad" => intval($cancha['capacidad'] ?? 4),
                    "tipo" => $cancha['tipo'] ?? 'Outdoor',
                    "superficie" => $cancha['superficie'] ?? 'Césped Sintético',
                    "disponible" => !$is_reserved,
                    "hora_fin" => $h_fin,
                    "precio_60" => (float)$cancha['precio_60'],
                    "precio_90" => (float)$cancha['precio_90'],
                    "precio_120" => (float)$cancha['precio_120'],
                    "precio_60_alto" => (float)$cancha['precio_60_alto'],
                    "precio_90_alto" => (float)$cancha['precio_90_alto'],
                    "precio_120_alto" => (float)$cancha['precio_120_alto'],
                    "precio_slot_60" => $p60_slot,
                    "precio_slot_90" => $p90_slot,
                    "precio_slot_120" => $p120_slot,
                    "nombre_tarifa" => $nombre_tarifa,
                    "hora_inicio_bajo" => $h_inicio_bajo_str,
                    "hora_fin_bajo" => $h_fin_bajo_str,
                    "hora_inicio_alto" => $h_inicio_alto_str,
                    "hora_fin_alto" => $h_fin_alto_str,
                    "es_horario_alto" => $es_horario_alto
                ];
            }
        }
    }
}

ksort($horarios_mapeados);

// Formatear respuesta para el frontend y filtrar si todas las canchas están ocupadas
$final_response = [];
foreach ($horarios_mapeados as $hora => $canchas_data) {
    $has_available = false;
    foreach ($canchas_data as $c) {
        if ($c['disponible']) {
            $has_available = true;
            break;
        }
    }
    if ($has_available) {
        $final_response[] = [
            "hora" => $hora,
            "canchas" => $canchas_data
        ];
    }
}

echo json_encode($final_response);
