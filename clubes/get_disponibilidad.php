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

$cancha_id = intval($_GET['cancha_id'] ?? 0);
$club_id   = intval($_GET['club_id'] ?? 0);
$fecha     = trim($_GET['fecha'] ?? date('Y-m-d'));

if (!$cancha_id && !$club_id) {
    http_response_code(400);
    echo json_encode(["error" => "cancha_id o club_id es requerido"]);
    exit;
}

// 1. Obtener las canchas involucradas
$canchas_ids = [];
if ($club_id > 0) {
    $sqlC = "SELECT id, nombre, tipo, superficie, categoria, icono, descripcion, capacidad, 
                    precio_60, precio_90, precio_120, precio_60_alto, precio_90_alto, precio_120_alto, 
                    hora_inicio_bajo, hora_fin_bajo, hora_inicio_alto, hora_fin_alto, horarios_adicionales 
             FROM canchas 
             WHERE club_id = ? AND activa = 1 
             ORDER BY id ASC";
    $stmtC = $conn->prepare($sqlC);
    $stmtC->bind_param("i", $club_id);
    $stmtC->execute();
    $resC = $stmtC->get_result();
    while ($c = $resC->fetch_assoc()) {
        $canchas_ids[] = $c;
    }
    $stmtC->close();
} else {
    $sqlC = "SELECT id, nombre, tipo, superficie, categoria, icono, descripcion, capacidad, 
                    precio_60, precio_90, precio_120, precio_60_alto, precio_90_alto, precio_120_alto, 
                    hora_inicio_bajo, hora_fin_bajo, hora_inicio_alto, hora_fin_alto, horarios_adicionales 
             FROM canchas 
             WHERE id = ? AND activa = 1 
             ORDER BY id ASC";
    $stmtC = $conn->prepare($sqlC);
    $stmtC->bind_param("i", $cancha_id);
    $stmtC->execute();
    $resC = $stmtC->get_result();
    if ($c = $resC->fetch_assoc()) {
        $canchas_ids[] = $c;
    }
    $stmtC->close();
}

if (empty($canchas_ids)) {
    echo json_encode([]);
    exit;
}

// Extraer lista de IDs de canchas
$cid_list = array_map(function($c) { return intval($c['id']); }, $canchas_ids);
$cid_placeholders = implode(',', array_fill(0, count($cid_list), '?'));
$cid_types = str_repeat('i', count($cid_list));

// 2. Pre-cargar configuraciones horarias por cancha para este día en 1 sola consulta
$dia_semana = intval(date('w', strtotime($fecha)));
$configs_por_cancha = [];

$sqlConfig = "SELECT * FROM cancha_horarios_config WHERE cancha_id IN ($cid_placeholders) AND dia_semana = ?";
$stmtConfig = $conn->prepare($sqlConfig);
if ($stmtConfig) {
    $bind_types = $cid_types . 'i';
    $bind_params = array_merge($cid_list, [$dia_semana]);
    $stmtConfig->bind_param($bind_types, ...$bind_params);
    $stmtConfig->execute();
    $resConfig = $stmtConfig->get_result();
    while ($rowConfig = $resConfig->fetch_assoc()) {
        $configs_por_cancha[intval($rowConfig['cancha_id'])][] = $rowConfig;
    }
    $stmtConfig->close();
}

// 3. Pre-cargar TODAS las reservas activas de la fecha en 1 sola consulta (Optimización Clave)
$reservas_por_cancha = [];
$sqlReservas = "SELECT cancha_id, 
                       TIME_TO_SEC(hora_inicio) AS start_sec, 
                       (CASE 
                           WHEN hora_fin IN ('00:00:00', '00:00', '24:00', '24:00:00', '23:59', '23:59:00', '23:59:59') OR hora_fin <= hora_inicio THEN 86400 
                           ELSE TIME_TO_SEC(hora_fin) 
                        END) AS end_sec 
                FROM reservas_cancha 
                WHERE cancha_id IN ($cid_placeholders) 
                  AND fecha = ? 
                  AND LOWER(COALESCE(estado, '')) NOT IN ('cancelada', 'cancelado', 'anulada', 'anulado', 'rechazada', 'rechazado', 'eliminada', 'eliminado')";

$stmtReservas = $conn->prepare($sqlReservas);
if ($stmtReservas) {
    $bind_types_r = $cid_types . 's';
    $bind_params_r = array_merge($cid_list, [$fecha]);
    $stmtReservas->bind_param($bind_types_r, ...$bind_params_r);
    $stmtReservas->execute();
    $resReservas = $stmtReservas->get_result();
    while ($r = $resReservas->fetch_assoc()) {
        $cId = intval($r['cancha_id']);
        $reservas_por_cancha[$cId][] = [
            'start' => intval($r['start_sec']),
            'end'   => intval($r['end_sec'])
        ];
    }
    $stmtReservas->close();
}

/**
 * Función ultra-rápida de verificación de disponibilidad en memoria RAM.
 * Reemplaza los cientos de llamadas a base de datos por comparaciones O(1) de enteros.
 */
function getFreeDurationFast($cancha, $start_sec, $fin_dia_sec, $reservasCancha) {
    // Verificar si la cancha tiene precios configurados para 60, 90, 120 min
    $has_120 = (float)($cancha['precio_120'] ?? 0) > 0 || (float)($cancha['precio_120_alto'] ?? 0) > 0;
    $has_90  = (float)($cancha['precio_90'] ?? 0) > 0 || (float)($cancha['precio_90_alto'] ?? 0) > 0;
    $has_60  = (float)($cancha['precio_60'] ?? 0) > 0 || (float)($cancha['precio_60_alto'] ?? 0) > 0;

    if (!$has_120 && !$has_90 && !$has_60) {
        $has_90 = true;
        $has_60 = true;
    }

    $is_120_ok = $has_120;
    $is_90_ok  = $has_90;
    $is_60_ok  = $has_60;

    // Verificar si excede el fin de la jornada
    if ($fin_dia_sec - $start_sec < 7200) { // 120 min
        $is_120_ok = false;
    }
    if ($fin_dia_sec - $start_sec < 5400) { // 90 min
        $is_90_ok = false;
    }
    if ($fin_dia_sec - $start_sec < 3600) { // 60 min
        $is_60_ok = false;
    }

    if (!$is_120_ok && !$is_90_ok && !$is_60_ok) {
        return 0;
    }

    // Colisiones con reservas existentes
    if (!empty($reservasCancha)) {
        $end_120 = min(86400, $start_sec + 7200);
        $end_90  = min(86400, $start_sec + 5400);
        $end_60  = min(86400, $start_sec + 3600);

        foreach ($reservasCancha as $res) {
            $r_start = $res['start'];
            $r_end   = $res['end'];

            if ($is_120_ok && $start_sec < $r_end && $end_120 > $r_start) {
                $is_120_ok = false;
            }
            if ($is_90_ok && $start_sec < $r_end && $end_90 > $r_start) {
                $is_90_ok = false;
            }
            if ($is_60_ok && $start_sec < $r_end && $end_60 > $r_start) {
                $is_60_ok = false;
            }

            if (!$is_120_ok && !$is_90_ok && !$is_60_ok) {
                break;
            }
        }
    }

    if ($is_120_ok) return 120;
    if ($is_90_ok)  return 90;
    if ($is_60_ok)  return 60;
    return 0;
}

$horarios_mapeados = [];

foreach ($canchas_ids as $cancha) {
    $cid = intval($cancha['id']);
    $cnombre = $cancha['nombre'];
    $reservasCancha = $reservas_por_cancha[$cid] ?? [];

    $h_inicio_bajo_str = !empty($cancha['hora_inicio_bajo']) ? substr($cancha['hora_inicio_bajo'], 0, 5) : '07:00';
    $h_fin_bajo_str    = !empty($cancha['hora_fin_bajo']) ? substr($cancha['hora_fin_bajo'], 0, 5) : '18:00';
    $h_inicio_alto_str = !empty($cancha['hora_inicio_alto']) ? substr($cancha['hora_inicio_alto'], 0, 5) : '18:00';
    $h_fin_alto_str    = !empty($cancha['hora_fin_alto']) ? substr($cancha['hora_fin_alto'], 0, 5) : '24:00';
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

    $configRows = $configs_por_cancha[$cid] ?? [];

    if (empty($configRows)) {
        // Bloques por defecto de 30 minutos de 07:00 a 24:00
        $inicio = strtotime("$fecha 07:00:00");
        $fin = strtotime("$fecha 00:00:00 +1 day");
        $bloque = 30 * 60; // 30 min default
        $fin_sec_dia = 86400;

        for ($t = $inicio; $t < $fin; $t += $bloque) {
            $h_inicio = date('H:i:s', $t);
            $h_fin_slot = ($t + $bloque >= $fin) ? '24:00:00' : date('H:i:s', $t + $bloque);
            $h_inicio_short = substr($h_inicio, 0, 5);

            // Determinar tarifa y tramos
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

            $start_sec = (intval(substr($h_inicio, 0, 2)) * 3600) + (intval(substr($h_inicio, 3, 2)) * 60) + intval(substr($h_inicio, 6, 2));
            $duracion_libre = getFreeDurationFast($cancha, $start_sec, $fin_sec_dia, $reservasCancha);
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
        foreach ($configRows as $config) {
            $inicio = strtotime("$fecha " . $config['hora_inicio']);
            $fin_str = $config['hora_fin'];
            if ($fin_str === '00:00:00' || $fin_str === '24:00:00' || $fin_str === '23:59:59' || $fin_str === '23:30:00') {
                $fin = strtotime("$fecha 00:00:00 +1 day");
                $fin_sec_dia = 86400;
            } else {
                $fin = strtotime("$fecha $fin_str");
                $fin_sec_dia = (intval(substr($fin_str, 0, 2)) * 3600) + (intval(substr($fin_str, 3, 2)) * 60);
            }
            $dur_bloque = intval($config['duracion_bloque'] ?? 30);
            $bloque = ($dur_bloque > 0 ? $dur_bloque : 30) * 60;

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

                $start_sec = (intval(substr($h_inicio, 0, 2)) * 3600) + (intval(substr($h_inicio, 3, 2)) * 60) + intval(substr($h_inicio, 6, 2));
                $duracion_libre = getFreeDurationFast($cancha, $start_sec, $fin_sec_dia, $reservasCancha);
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
