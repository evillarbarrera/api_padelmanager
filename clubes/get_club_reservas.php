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

require_once "../db.php";

$headers = getallheaders();
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';

// Simple Auth Check
if (empty($auth)) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$club_id = $_GET['club_id'] ?? 0;
$fecha = $_GET['fecha'] ?? date('Y-m-d');

if (!$club_id) {
    echo json_encode([]);
    exit;
}

// 0. Saneamiento inicial: Convertir 0 a NULL en referencias foráneas de torneos y ligas
$conn->query("UPDATE reservas_cancha SET liga_partido_id = NULL WHERE liga_partido_id = 0");
$conn->query("UPDATE reservas_cancha SET torneo_id = NULL WHERE torneo_id = 0");

// 1. Clean up any stale or orphaned match reservations (e.g. matches that were unscheduled, deleted, or moved)
$conn->query("DELETE r FROM reservas_cancha r 
              LEFT JOIN liga_partidos lp ON r.liga_partido_id = lp.id 
              WHERE r.liga_partido_id IS NOT NULL 
                AND r.liga_partido_id > 0
                AND (lp.id IS NULL OR lp.fecha_hora IS NULL OR lp.fecha_hora LIKE '0000%' OR lp.cancha_id IS NULL 
                     OR DATE(lp.fecha_hora) != r.fecha OR lp.cancha_id != r.cancha_id)");

// 1.1 Asegurar que todas las reservas de liga tengan su duración oficial de 90 minutos (1h 30m)
$conn->query("UPDATE reservas_cancha 
              SET hora_fin = ADDTIME(hora_inicio, '01:30:00') 
              WHERE liga_partido_id IS NOT NULL 
                AND liga_partido_id > 0 
                AND (TIMESTAMPDIFF(MINUTE, hora_inicio, hora_fin) != 90 OR hora_fin IS NULL)");

// 1.2 Auto-crear reservas faltantes para partidos de liga que ya tengan cancha y fecha asignada
$conn->query("INSERT INTO reservas_cancha (cancha_id, fecha, hora_inicio, hora_fin, precio, precio_total, pagado, estado, liga_partido_id, nombre_externo, nombre_externo2, nombre_externo3, nombre_externo4, usuario_id, jugador2_id, jugador3_id, jugador4_id)
              SELECT lp.cancha_id, 
                     DATE(lp.fecha_hora), 
                     TIME(lp.fecha_hora), 
                     ADDTIME(TIME(lp.fecha_hora), '01:30:00'), 
                     0, 0, 1, 'Confirmada', lp.id,
                     COALESCE(u11.nombre, par1.nombre_externo1, 'P1-J1'),
                     COALESCE(u12.nombre, par1.nombre_externo2, 'P1-J2'),
                     COALESCE(u21.nombre, par2.nombre_externo1, 'P2-J1'),
                     COALESCE(u22.nombre, par2.nombre_externo2, 'P2-J2'),
                     par1.jugador1_id, par1.jugador2_id, par2.jugador1_id, par2.jugador2_id
              FROM liga_partidos lp
              JOIN canchas c ON lp.cancha_id = c.id
              LEFT JOIN reservas_cancha r ON r.liga_partido_id = lp.id
              LEFT JOIN liga_parejas par1 ON lp.pareja1_id = par1.id
              LEFT JOIN liga_parejas par2 ON lp.pareja2_id = par2.id
              LEFT JOIN usuarios u11 ON par1.jugador1_id = u11.id
              LEFT JOIN usuarios u12 ON par1.jugador2_id = u12.id
              LEFT JOIN usuarios u21 ON par2.jugador1_id = u21.id
              LEFT JOIN usuarios u22 ON par2.jugador2_id = u22.id
              WHERE c.club_id = $club_id
                AND lp.fecha_hora IS NOT NULL 
                AND lp.fecha_hora NOT LIKE '0000%' 
                AND lp.cancha_id IS NOT NULL 
                AND lp.estado != 'Suspendido'
                AND r.id IS NULL");

// 2. Limpieza de reservas de torneos americanos desincronizados o cerrados
// 2.1 Con torneo_id explícito: si el torneo no existe, cambió de fecha, o está Cerrado/Finalizado/Cancelado
$conn->query("DELETE r FROM reservas_cancha r 
              LEFT JOIN torneos_americanos ta ON r.torneo_id = ta.id 
              WHERE r.torneo_id IS NOT NULL 
                AND r.torneo_id > 0 
                AND (ta.id IS NULL 
                     OR LOWER(TRIM(ta.estado)) IN ('cerrado', 'finalizado', 'terminado', 'cancelado') 
                     OR r.fecha != ta.fecha)");

// 2.2 Sin torneo_id o huérfanas: reservas marcadas como 'americano' en este club y fecha donde el torneo en esa fecha esté Cerrado/Cancelado
$conn->query("DELETE r FROM reservas_cancha r
              JOIN canchas c ON r.cancha_id = c.id
              JOIN torneos_americanos ta ON ta.club_id = c.club_id AND ta.fecha = r.fecha
              WHERE c.club_id = $club_id AND r.fecha = '$fecha'
                AND (r.torneo_id IS NULL OR r.torneo_id = 0 OR r.torneo_id = ta.id)
                AND (r.tipo_reserva = 'americano' OR r.estado = 'Americano' OR LOWER(r.nombre_externo) LIKE '%americano%')
                AND LOWER(TRIM(ta.estado)) IN ('cerrado', 'finalizado', 'terminado', 'cancelado')");

// 2.3 Reservas marcadas como americano en este club y fecha cuando NO existe ningún torneo americano Abierto en este club en esta fecha
$checkOpenAm = $conn->query("SELECT id FROM torneos_americanos WHERE club_id = $club_id AND fecha = '$fecha' AND LOWER(TRIM(estado)) = 'abierto' LIMIT 1");
if (!$checkOpenAm || $checkOpenAm->num_rows == 0) {
    // Si no hay torneo abierto hoy en este club, cualquier reserva de tipo americano huérfana en esta fecha se elimina
    $conn->query("DELETE r FROM reservas_cancha r 
                  JOIN canchas c ON r.cancha_id = c.id 
                  WHERE c.club_id = $club_id AND r.fecha = '$fecha' 
                    AND (r.tipo_reserva = 'americano' OR r.estado = 'Americano' OR LOWER(r.nombre_externo) LIKE '%americano%')");
}

// 3. Limpiar reservas desincronizadas con fecha diferente por nombre
$conn->query("DELETE r FROM reservas_cancha r
              JOIN canchas c ON r.cancha_id = c.id
              JOIN torneos_americanos ta ON ta.club_id = c.club_id AND ta.nombre = r.nombre_externo
              WHERE (r.tipo_reserva = 'americano' OR r.estado = 'Americano')
                AND r.fecha != ta.fecha");

// 3.1 Auto-crear / sincronizar reservas faltantes para torneos americanos activos en la fecha solicitada
$resTorneosActivos = $conn->query("SELECT * FROM torneos_americanos WHERE club_id = $club_id AND fecha = '$fecha' AND estado = 'Abierto'");
if ($resTorneosActivos && $resTorneosActivos->num_rows > 0) {
    while ($ta = $resTorneosActivos->fetch_assoc()) {
        $t_id = (int)$ta['id'];
        $num_c = max(1, (int)$ta['num_canchas']);
        $t_part = max(10, (int)$ta['tiempo_por_partido']);
        $max_p = max(4, (int)$ta['max_parejas']);
        $tipo_t = $ta['tipo_torneo'] ?? 'estandar';

        if ($tipo_t === 'grupos' && $max_p === 8) {
            $total_partidos = 14;
            $slots = ceil($total_partidos / $num_c);
            $duracion_min = $slots * $t_part;
        } else {
            $total_partidos = ($max_p * ($max_p - 1)) / 2;
            $slots = ceil($total_partidos / $num_c);
            $duracion_min = $slots * $t_part;
        }
        $duracion_min = max(90, min(360, (int)$duracion_min));
        $duracion_min = ceil($duracion_min / 30) * 30;

        $start_ts = strtotime($ta['hora_inicio']);
        $end_ts = $start_ts + ($duracion_min * 60);
        $h_ini = date('H:i:s', $start_ts);
        $h_fin = date('H:i:s', $end_ts);
        if (date('H:i', $end_ts) === '00:00' || (date('H', $end_ts) == '00' && date('i', $end_ts) == '00')) {
            $h_fin = '24:00:00';
        }

        $chkRes = $conn->query("SELECT id, cancha_id FROM reservas_cancha WHERE torneo_id = $t_id AND fecha = '$fecha'");
        $existingCanchas = [];
        if ($chkRes) {
            while ($rowR = $chkRes->fetch_assoc()) {
                $existingCanchas[] = (int)$rowR['cancha_id'];
            }
        }

        if (count($existingCanchas) < $num_c) {
            $sqlC = "SELECT id FROM canchas WHERE club_id = $club_id AND (activa = 1 OR activa IS NULL) ORDER BY id ASC LIMIT $num_c";
            $resC = $conn->query($sqlC);
            if ($resC) {
                while ($rowC = $resC->fetch_assoc()) {
                    $cid = (int)$rowC['id'];
                    if (!in_array($cid, $existingCanchas)) {
                        // Validar que la cancha no tenga ya una reserva de jugador confirmada en ese intervalo
                        $checkConflict = $conn->query("SELECT id FROM reservas_cancha 
                                                       WHERE cancha_id = $cid AND fecha = '$fecha' 
                                                         AND LOWER(estado) NOT IN ('cancelada', 'cancelado', 'anulada', 'anulado', 'rechazada', 'eliminada')
                                                         AND torneo_id IS NULL 
                                                         AND (hora_inicio < '$h_fin' AND hora_fin > '$h_ini')");
                        if ($checkConflict && $checkConflict->num_rows > 0) {
                            continue;
                        }

                        $nomT = $conn->real_escape_string($ta['nombre']);
                        $creator_id = (int)($ta['creator_id'] ?? 0);
                        $conn->query("INSERT INTO reservas_cancha 
                                      (cancha_id, usuario_id, fecha, hora_inicio, hora_fin, precio, precio_total, pagado, estado, tipo_reserva, nombre_externo, torneo_id) 
                                      VALUES ($cid, $creator_id, '$fecha', '$h_ini', '$h_fin', 0, 0, 0, 'Americano', 'americano', '$nomT', $t_id)");
                    }
                }
            }
        } else {
            $nomT = $conn->real_escape_string($ta['nombre']);
            $conn->query("UPDATE reservas_cancha 
                          SET hora_inicio = '$h_ini', hora_fin = '$h_fin', nombre_externo = '$nomT', estado = 'Americano', tipo_reserva = 'americano' 
                          WHERE torneo_id = $t_id AND fecha = '$fecha'");
        }
    }
}

// 4. Clean up identical duplicate active reservations
$conn->query("DELETE r1 FROM reservas_cancha r1
              JOIN reservas_cancha r2 ON r1.cancha_id = r2.cancha_id 
                                     AND r1.fecha = r2.fecha 
                                     AND r1.hora_inicio = r2.hora_inicio 
                                     AND r1.hora_fin = r2.hora_fin 
                                     AND r1.id > r2.id
              WHERE (
                 (r1.torneo_id IS NOT NULL AND r1.torneo_id > 0 AND r1.torneo_id = r2.torneo_id)
                 OR (r1.liga_partido_id IS NOT NULL AND r1.liga_partido_id > 0 AND r1.liga_partido_id = r2.liga_partido_id)
                 OR (
                     TRIM(LOWER(r1.estado)) NOT IN ('cancelada', 'cancelado', 'anulada', 'anulado')
                     AND TRIM(LOWER(r2.estado)) NOT IN ('cancelada', 'cancelado', 'anulada', 'anulado')
                     AND r1.estado = r2.estado
                     AND (
                         (r1.usuario_id IS NOT NULL AND r1.usuario_id = r2.usuario_id AND r1.usuario_id > 0)
                         OR (r1.nombre_externo IS NOT NULL AND r1.nombre_externo != '' AND r1.nombre_externo = r2.nombre_externo)
                     )
                 )
              )");

// Get all reservations for all courts of this club on a specific date
$sql = "SELECT r.*, c.nombre as cancha_nombre, c.tipo as cancha_tipo, c.categoria as cancha_categoria, c.icono as cancha_icono, c.capacidad as cancha_capacidad,
               u1.nombre as jugador_nombre, COALESCE(u1.apellido, '') as jugador_apellido,
               u2.nombre as jugador2_nombre, COALESCE(u2.apellido, '') as jugador2_apellido,
               u3.nombre as jugador3_nombre, COALESCE(u3.apellido, '') as jugador3_apellido,
               u4.nombre as jugador4_nombre, COALESCE(u4.apellido, '') as jugador4_apellido,
               lp.id as lp_id, lp.categoria_id as lp_cat_id,
               par1.nombre_pareja as par1_nom_pareja, par1.nombre_externo1 as par1_ext1, par1.nombre_externo2 as par1_ext2,
               par2.nombre_pareja as par2_nom_pareja, par2.nombre_externo1 as par2_ext1, par2.nombre_externo2 as par2_ext2,
               lp_u1.nombre as lp_u1_nom, COALESCE(lp_u1.apellido, '') as lp_u1_ape,
               lp_u2.nombre as lp_u2_nom, COALESCE(lp_u2.apellido, '') as lp_u2_ape,
               lp_u3.nombre as lp_u3_nom, COALESCE(lp_u3.apellido, '') as lp_u3_ape,
               lp_u4.nombre as lp_u4_nom, COALESCE(lp_u4.apellido, '') as lp_u4_ape,
               lcat.nombre as categoria_nombre, lig.nombre as liga_nombre,
               ta.nombre as torneo_nombre, ta.categoria as torneo_categoria, ta.tipo_torneo as torneo_tipo_torneo
        FROM reservas_cancha r 
        JOIN canchas c ON r.cancha_id = c.id 
        LEFT JOIN usuarios u1 ON r.usuario_id = u1.id 
        LEFT JOIN usuarios u2 ON r.jugador2_id = u2.id 
        LEFT JOIN usuarios u3 ON r.jugador3_id = u3.id 
        LEFT JOIN usuarios u4 ON r.jugador4_id = u4.id 
        LEFT JOIN liga_partidos lp ON r.liga_partido_id = lp.id
        LEFT JOIN liga_parejas par1 ON lp.pareja1_id = par1.id
        LEFT JOIN liga_parejas par2 ON lp.pareja2_id = par2.id
        LEFT JOIN usuarios lp_u1 ON par1.jugador1_id = lp_u1.id
        LEFT JOIN usuarios lp_u2 ON par1.jugador2_id = lp_u2.id
        LEFT JOIN usuarios lp_u3 ON par2.jugador1_id = lp_u3.id
        LEFT JOIN usuarios lp_u4 ON par2.jugador2_id = lp_u4.id
        LEFT JOIN liga_categorias lcat ON (lp.categoria_id = lcat.id OR par1.categoria_id = lcat.id)
        LEFT JOIN ligas lig ON lcat.liga_id = lig.id
        LEFT JOIN torneos_americanos ta ON r.torneo_id = ta.id
        WHERE c.club_id = ? AND r.fecha = ?
          AND (r.liga_partido_id IS NULL OR r.liga_partido_id = 0 OR (lp.id IS NOT NULL AND lp.fecha_hora IS NOT NULL AND lp.fecha_hora NOT LIKE '0000%' AND lp.cancha_id IS NOT NULL))
          AND LOWER(COALESCE(r.estado, '')) NOT IN ('cancelada', 'cancelado', 'anulada', 'anulado', 'rechazada', 'eliminada')
        ORDER BY r.hora_inicio ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $club_id, $fecha);
$stmt->execute();
$result = $stmt->get_result();

$reservas = [];
while ($row = $result->fetch_assoc()) {
    if (!empty($row['liga_partido_id']) && !empty($row['lp_id'])) {
        $nom1 = trim(($row['lp_u1_nom'] ?? '') . ' ' . ($row['lp_u1_ape'] ?? ''));
        if (empty($nom1)) $nom1 = $row['par1_ext1'] ?: ($row['jugador_nombre'] ?: ($row['nombre_externo'] ?: 'P1-J1'));

        $nom2 = trim(($row['lp_u2_nom'] ?? '') . ' ' . ($row['lp_u2_ape'] ?? ''));
        if (empty($nom2)) $nom2 = $row['par1_ext2'] ?: ($row['jugador2_nombre'] ?: ($row['nombre_externo2'] ?: 'P1-J2'));

        $nom3 = trim(($row['lp_u3_nom'] ?? '') . ' ' . ($row['lp_u3_ape'] ?? ''));
        if (empty($nom3)) $nom3 = $row['par2_ext1'] ?: ($row['jugador3_nombre'] ?: ($row['nombre_externo3'] ?: 'P2-J1'));

        $nom4 = trim(($row['lp_u4_nom'] ?? '') . ' ' . ($row['lp_u4_ape'] ?? ''));
        if (empty($nom4)) $nom4 = $row['par2_ext2'] ?: ($row['jugador4_nombre'] ?: ($row['nombre_externo4'] ?: 'P2-J2'));

        $row['jugador_nombre'] = $nom1;
        $row['nombre_externo'] = $nom1;
        $row['jugador2_nombre'] = $nom2;
        $row['nombre_externo2'] = $nom2;
        $row['jugador3_nombre'] = $nom3;
        $row['nombre_externo3'] = $nom3;
        $row['jugador4_nombre'] = $nom4;
        $row['nombre_externo4'] = $nom4;
    } else {
        if (!empty($row['jugador_nombre']) && !empty($row['jugador_apellido']) && strpos($row['jugador_nombre'], $row['jugador_apellido']) === false) {
            $row['jugador_nombre'] = trim($row['jugador_nombre'] . ' ' . $row['jugador_apellido']);
        }
        if (!empty($row['jugador2_nombre']) && !empty($row['jugador2_apellido']) && strpos($row['jugador2_nombre'], $row['jugador2_apellido']) === false) {
            $row['jugador2_nombre'] = trim($row['jugador2_nombre'] . ' ' . $row['jugador2_apellido']);
        }
        if (!empty($row['jugador3_nombre']) && !empty($row['jugador3_apellido']) && strpos($row['jugador3_nombre'], $row['jugador3_apellido']) === false) {
            $row['jugador3_nombre'] = trim($row['jugador3_nombre'] . ' ' . $row['jugador3_apellido']);
        }
        if (!empty($row['jugador4_nombre']) && !empty($row['jugador4_apellido']) && strpos($row['jugador4_nombre'], $row['jugador4_apellido']) === false) {
            $row['jugador4_nombre'] = trim($row['jugador4_nombre'] . ' ' . $row['jugador4_apellido']);
        }
    }

    $isRowAmericano = (
        !empty($row['torneo_id']) ||
        ($row['tipo_reserva'] ?? '') === 'americano' ||
        ($row['estado'] ?? '') === 'Americano' ||
        stripos($row['nombre_externo'] ?? '', 'americano') !== false
    );

    if (!empty($row['torneo_id'])) {
        if (!empty($row['torneo_nombre'])) {
            $row['nombre_externo'] = $row['torneo_nombre'];
        }
        $row['tipo_reserva'] = 'americano';
        $row['estado'] = 'Americano';
    }

    if ($isRowAmericano) {
        $row['pagado'] = 1;
    }

    $reservas[] = $row;
}

function roundUpToSlot30($timeStr) {
    if (!$timeStr) return '00:00:00';
    if ($timeStr === '00:00:00' || $timeStr === '24:00:00' || $timeStr === '00:00' || $timeStr === '24:00') return '24:00:00';
    $parts = explode(':', $timeStr);
    $h = (int)($parts[0] ?? 0);
    $m = (int)($parts[1] ?? 0);
    if ($m > 0 && $m <= 30) {
        $m = 30;
    } else if ($m > 30) {
        $m = 0;
        $h += 1;
    }
    if ($h >= 24) return '24:00:00';
    return sprintf('%02d:%02d:00', $h, $m);
}

// Consolidar fragmentos de torneos americanos por cancha para que formen un solo bloque continuo
$consolidated = [];
$americanoGroups = [];

foreach ($reservas as $r) {
    $isAmericano = (!empty($r['torneo_id']) && (int)$r['torneo_id'] > 0) ||
                   (strcasecmp($r['estado'] ?? '', 'Americano') === 0) ||
                   (strcasecmp($r['tipo_reserva'] ?? '', 'americano') === 0) ||
                   (stripos($r['nombre_externo'] ?? '', 'americano') !== false);

    if ($isAmericano) {
        $r['tipo_reserva'] = 'americano';
        $r['estado'] = 'Americano';
        if (!empty($r['torneo_nombre'])) {
            $r['nombre_externo'] = $r['torneo_nombre'];
        }

        $groupKey = $r['cancha_id'] . '_' . ($r['torneo_id'] ?: $r['nombre_externo']);

        if (isset($americanoGroups[$groupKey])) {
            $existingIdx = $americanoGroups[$groupKey];
            
            $currStart = $consolidated[$existingIdx]['hora_inicio'];
            $currEnd = $consolidated[$existingIdx]['hora_fin'];
            
            $currStartM = strtotime($currStart);
            $newStartM = strtotime($r['hora_inicio']);
            $currEndM = ($currEnd === '00:00:00' || $currEnd === '24:00:00') ? strtotime('23:59:59') + 1 : strtotime($currEnd);
            $newEndM = ($r['hora_fin'] === '00:00:00' || $r['hora_fin'] === '24:00:00') ? strtotime('23:59:59') + 1 : strtotime($r['hora_fin']);

            if ($newStartM < $currStartM) {
                $consolidated[$existingIdx]['hora_inicio'] = $r['hora_inicio'];
            }
            if ($newEndM > $currEndM) {
                $consolidated[$existingIdx]['hora_fin'] = $r['hora_fin'];
            }
            if (!empty($r['torneo_nombre']) && empty($consolidated[$existingIdx]['torneo_nombre'])) {
                $consolidated[$existingIdx]['torneo_nombre'] = $r['torneo_nombre'];
            }
        } else {
            $americanoGroups[$groupKey] = count($consolidated);
            $consolidated[] = $r;
        }
    } else {
        $consolidated[] = $r;
    }
}

// Redondear la hora_fin de todos los Americanos al siguiente bloque de 30 minutos para alineación perfecta
foreach ($consolidated as &$cItem) {
    $isAmer = (!empty($cItem['torneo_id']) && (int)$cItem['torneo_id'] > 0) ||
              ($cItem['estado'] ?? '') === 'Americano' ||
              ($cItem['tipo_reserva'] ?? '') === 'americano';
    if ($isAmer) {
        $cItem['hora_fin'] = roundUpToSlot30($cItem['hora_fin']);
    }
}
unset($cItem);

echo json_encode($consolidated);
