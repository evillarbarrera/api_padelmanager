<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
require_once "../db.php";

$input = json_decode(file_get_contents("php://input"), true);

$partido_id = !empty($input['partido_id']) ? (int)$input['partido_id'] : null;
$cancha_id = !empty($input['cancha_id']) ? (int)$input['cancha_id'] : null;
$fecha_hora = !empty($input['fecha_hora']) ? trim($input['fecha_hora']) : null;
$duracion_minutos = !empty($input['duracion_minutos']) ? (int)$input['duracion_minutos'] : (!empty($input['duracion']) ? (int)$input['duracion'] : 90);

if (!$partido_id) {
    echo json_encode(["success" => false, "error" => "ID de partido requerido"]);
    exit;
}

try {
    if ($cancha_id && $fecha_hora) {
        $duracion_segundos = $duracion_minutos * 60;
        $fechaRes = date('Y-m-d', strtotime($fecha_hora));
        $horaIniRes = date('H:i:s', strtotime($fecha_hora));
        
        $end_ts = strtotime($fecha_hora) + $duracion_segundos;
        if ($end_ts >= strtotime("$fechaRes 00:00:00 +1 day")) {
            $horaFinRes = "24:00:00";
        } else {
            $horaFinRes = date('H:i:s', $end_ts);
        }

        $hIniTarget = substr($horaIniRes, 0, 5); // e.g. "20:00"
        $hFinTarget = substr($horaFinRes, 0, 5); // e.g. "21:30"

        // 1. Obtener detalles del partido a programar y sus participantes
        $sql_detail = "SELECT p.pareja1_id, p.pareja2_id, p.cancha_id, p.fecha_hora,
                              par1.nombre_pareja as p1_nom, par2.nombre_pareja as p2_nom,
                              par1.jugador1_id as p1_j1_id, par1.jugador2_id as p1_j2_id,
                              par2.jugador1_id as p2_j1_id, par2.jugador2_id as p2_j2_id,
                              par1.nombre_externo1 as p1_j1_ext, par1.nombre_externo2 as p1_j2_ext,
                              par2.nombre_externo1 as p2_j1_ext, par2.nombre_externo2 as p2_j2_ext,
                              u11.nombre as p1_j1_u, COALESCE(u11.apellido, '') as p1_j1_ape,
                              u12.nombre as p1_j2_u, COALESCE(u12.apellido, '') as p1_j2_ape,
                              u21.nombre as p2_j1_u, COALESCE(u21.apellido, '') as p2_j1_ape,
                              u22.nombre as p2_j2_u, COALESCE(u22.apellido, '') as p2_j2_ape
                       FROM liga_partidos p
                       LEFT JOIN liga_parejas par1 ON p.pareja1_id = par1.id
                       LEFT JOIN liga_parejas par2 ON p.pareja2_id = par2.id
                       LEFT JOIN usuarios u11 ON par1.jugador1_id = u11.id
                       LEFT JOIN usuarios u12 ON par1.jugador2_id = u12.id
                       LEFT JOIN usuarios u21 ON par2.jugador1_id = u21.id
                       LEFT JOIN usuarios u22 ON par2.jugador2_id = u22.id
                       WHERE p.id = $partido_id";

        $res_p = $conn->query($sql_detail);
        if (!$res_p || $res_p->num_rows === 0) {
            echo json_encode(["success" => false, "error" => "Partido no encontrado."]);
            exit;
        }
        $row_p = $res_p->fetch_assoc();

        $p1_id = (int)($row_p['pareja1_id'] ?? 0);
        $p2_id = (int)($row_p['pareja2_id'] ?? 0);
        $p1_nom = !empty($row_p['p1_nom']) ? $row_p['p1_nom'] : 'Pareja 1';
        $p2_nom = !empty($row_p['p2_nom']) ? $row_p['p2_nom'] : 'Pareja 2';

        $j1_id = (!empty($row_p['p1_j1_id']) && (int)$row_p['p1_j1_id'] > 0) ? (int)$row_p['p1_j1_id'] : null;
        $j2_id = (!empty($row_p['p1_j2_id']) && (int)$row_p['p1_j2_id'] > 0) ? (int)$row_p['p1_j2_id'] : null;
        $j3_id = (!empty($row_p['p2_j1_id']) && (int)$row_p['p2_j1_id'] > 0) ? (int)$row_p['p2_j1_id'] : null;
        $j4_id = (!empty($row_p['p2_j2_id']) && (int)$row_p['p2_j2_id'] > 0) ? (int)$row_p['p2_j2_id'] : null;

        $jugadoresMap = [];
        $n1 = trim(($row_p['p1_j1_u'] ?? '') . ' ' . ($row_p['p1_j1_ape'] ?? ''));
        $ext1 = !empty($n1) ? $n1 : (!empty($row_p['p1_j1_ext']) ? $row_p['p1_j1_ext'] : 'P1-J1');
        if ($j1_id) $jugadoresMap[$j1_id] = $ext1;

        $n2 = trim(($row_p['p1_j2_u'] ?? '') . ' ' . ($row_p['p1_j2_ape'] ?? ''));
        $ext2 = !empty($n2) ? $n2 : (!empty($row_p['p1_j2_ext']) ? $row_p['p1_j2_ext'] : 'P1-J2');
        if ($j2_id) $jugadoresMap[$j2_id] = $ext2;

        $n3 = trim(($row_p['p2_j1_u'] ?? '') . ' ' . ($row_p['p2_j1_ape'] ?? ''));
        $ext3 = !empty($n3) ? $n3 : (!empty($row_p['p2_j1_ext']) ? $row_p['p2_j1_ext'] : 'P2-J1');
        if ($j3_id) $jugadoresMap[$j3_id] = $ext3;

        $n4 = trim(($row_p['p2_j2_u'] ?? '') . ' ' . ($row_p['p2_j2_ape'] ?? ''));
        $ext4 = !empty($n4) ? $n4 : (!empty($row_p['p2_j2_ext']) ? $row_p['p2_j2_ext'] : 'P2-J2');
        if ($j4_id) $jugadoresMap[$j4_id] = $ext4;

        // 2. Validar si la cancha ya tiene una reserva activa en ese horario
        $sql_check_res = "SELECT r.id, r.hora_inicio, r.hora_fin, r.nombre_externo, c.nombre as cancha_nom 
                          FROM reservas_cancha r
                          LEFT JOIN canchas c ON r.cancha_id = c.id
                          WHERE r.cancha_id = ? 
                            AND r.fecha = ? 
                            AND LOWER(r.estado) NOT IN ('cancelada', 'cancelado', 'anulada', 'anulado', 'rechazada', 'rechazado', 'eliminada', 'eliminado', 'inactiva', 'inactivo')
                            AND (r.liga_partido_id IS NULL OR r.liga_partido_id != ?)
                            AND SUBSTRING(r.hora_inicio, 1, 5) < ? 
                            AND (CASE 
                                   WHEN r.hora_fin IN ('00:00:00', '00:00', '24:00', '24:00:00', '23:59', '23:59:00', '23:59:59') THEN '24:00' 
                                   ELSE SUBSTRING(r.hora_fin, 1, 5) 
                                 END) > ?
                          LIMIT 1";
        $stmt_check = $conn->prepare($sql_check_res);
        $stmt_check->bind_param("isiss", $cancha_id, $fechaRes, $partido_id, $hFinTarget, $hIniTarget);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();
        if ($res_check && $res_check->num_rows > 0) {
            $conf = $res_check->fetch_assoc();
            $hIni = substr($conf['hora_inicio'], 0, 5);
            $hFin = substr($conf['hora_fin'], 0, 5);
            $cNom = !empty($conf['cancha_nom']) ? " en {$conf['cancha_nom']}" : "";
            $nomExt = !empty($conf['nombre_externo']) ? " ({$conf['nombre_externo']})" : "";
            echo json_encode([
                "success" => false, 
                "error" => "⚠️ La cancha ya se encuentra ocupada el $fechaRes entre $hIni y $hFin$nomExt$cNom (Reserva #{$conf['id']}). Por favor selecciona otra cancha u otro horario."
            ]);
            exit;
        }

        // 3. Validar si la cancha ya está asignada a otro partido de liga que se traslape
        $sql_check_part = "SELECT p.id, p.fecha_hora, par1.nombre_pareja as p1, par2.nombre_pareja as p2, c.nombre as cancha_nom
                           FROM liga_partidos p
                           LEFT JOIN canchas c ON p.cancha_id = c.id
                           LEFT JOIN liga_parejas par1 ON p.pareja1_id = par1.id
                           LEFT JOIN liga_parejas par2 ON p.pareja2_id = par2.id
                           WHERE p.cancha_id = ? 
                             AND p.id != ? 
                             AND p.estado NOT IN ('Cancelado', 'Suspendido') 
                             AND p.fecha_hora IS NOT NULL 
                             AND DATE(p.fecha_hora) = ?
                             AND SUBSTRING(TIME(p.fecha_hora), 1, 5) < ? 
                             AND SUBSTRING(TIME(DATE_ADD(p.fecha_hora, INTERVAL ? MINUTE)), 1, 5) > ?
                           LIMIT 1";
        $stmt_check_p = $conn->prepare($sql_check_part);
        $stmt_check_p->bind_param("iissis", $cancha_id, $partido_id, $fechaRes, $hFinTarget, $duracion_minutos, $hIniTarget);
        $stmt_check_p->execute();
        $res_check_p = $stmt_check_p->get_result();
        if ($res_check_p && $res_check_p->num_rows > 0) {
            $conf_p = $res_check_p->fetch_assoc();
            $horaConf = date('H:i', strtotime($conf_p['fecha_hora']));
            $cNom = !empty($conf_p['cancha_nom']) ? " en {$conf_p['cancha_nom']}" : "";
            $matchNom = trim(($conf_p['p1'] ?? '') . ' vs ' . ($conf_p['p2'] ?? ''));
            echo json_encode([
                "success" => false, 
                "error" => "⚠️ La cancha ya tiene programado el partido '$matchNom' a las $horaConf$cNom. Por favor selecciona otro horario o cancha."
            ]);
            exit;
        }

        // 4. Validar conflicto de Parejas (Ninguna de las dos parejas puede tener otro partido agendado a la misma hora)
        if ($p1_id > 0 || $p2_id > 0) {
            $pIdsArr = array_filter([$p1_id, $p2_id]);
            $pIdsStr = implode(',', $pIdsArr);
            $sql_chk_pair = "SELECT p.id, p.fecha_hora, p.pareja1_id, p.pareja2_id,
                                    par1.nombre_pareja as p1, par2.nombre_pareja as p2,
                                    c.nombre as cancha_nom
                             FROM liga_partidos p
                             LEFT JOIN canchas c ON p.cancha_id = c.id
                             LEFT JOIN liga_parejas par1 ON p.pareja1_id = par1.id
                             LEFT JOIN liga_parejas par2 ON p.pareja2_id = par2.id
                             WHERE p.id != ?
                               AND p.estado NOT IN ('Cancelado', 'Suspendido')
                               AND p.fecha_hora IS NOT NULL
                               AND DATE(p.fecha_hora) = ?
                               AND (p.pareja1_id IN ($pIdsStr) OR p.pareja2_id IN ($pIdsStr))
                               AND SUBSTRING(TIME(p.fecha_hora), 1, 5) < ?
                               AND SUBSTRING(TIME(DATE_ADD(p.fecha_hora, INTERVAL ? MINUTE)), 1, 5) > ?
                             LIMIT 1";
            $stmt_chk_pair = $conn->prepare($sql_chk_pair);
            $stmt_chk_pair->bind_param("isssi", $partido_id, $fechaRes, $hFinTarget, $duracion_minutos, $hIniTarget);
            $stmt_chk_pair->execute();
            $res_chk_pair = $stmt_chk_pair->get_result();
            if ($res_chk_pair && $res_chk_pair->num_rows > 0) {
                $conf_pair = $res_chk_pair->fetch_assoc();
                $horaConf = date('H:i', strtotime($conf_pair['fecha_hora']));
                $cNom = !empty($conf_pair['cancha_nom']) ? " en {$conf_pair['cancha_nom']}" : "";
                $nomParejaConf = ($conf_pair['pareja1_id'] == $p1_id || $conf_pair['pareja2_id'] == $p1_id) ? $p1_nom : $p2_nom;
                $matchNom = trim(($conf_pair['p1'] ?? 'Pareja 1') . ' vs ' . ($conf_pair['p2'] ?? 'Pareja 2'));
                
                echo json_encode([
                    "success" => false,
                    "error" => "⚠️ La pareja '$nomParejaConf' ya tiene agendado el partido '$matchNom' el $fechaRes a las $horaConf$cNom. No se puede agendar a la misma pareja a la misma hora."
                ]);
                exit;
            }
        }

        // 5. Validar conflicto de Jugadores registrados (Ningún jugador puede jugar 2 partidos simultáneamente)
        if (!empty($jugadoresMap)) {
            $jIds = array_keys($jugadoresMap);
            $jIdsStr = implode(',', $jIds);
            $sql_chk_jug = "SELECT p.id, p.fecha_hora,
                                   par1.nombre_pareja as p1, par2.nombre_pareja as p2,
                                   par1.jugador1_id as p1_j1, par1.jugador2_id as p1_j2,
                                   par2.jugador1_id as p2_j1, par2.jugador2_id as p2_j2,
                                   c.nombre as cancha_nom
                            FROM liga_partidos p
                            LEFT JOIN canchas c ON p.cancha_id = c.id
                            LEFT JOIN liga_parejas par1 ON p.pareja1_id = par1.id
                            LEFT JOIN liga_parejas par2 ON p.pareja2_id = par2.id
                            WHERE p.id != ?
                              AND p.estado NOT IN ('Cancelado', 'Suspendido')
                              AND p.fecha_hora IS NOT NULL
                              AND DATE(p.fecha_hora) = ?
                              AND (
                                par1.jugador1_id IN ($jIdsStr) OR par1.jugador2_id IN ($jIdsStr) OR
                                par2.jugador1_id IN ($jIdsStr) OR par2.jugador2_id IN ($jIdsStr)
                              )
                              AND SUBSTRING(TIME(p.fecha_hora), 1, 5) < ?
                              AND SUBSTRING(TIME(DATE_ADD(p.fecha_hora, INTERVAL ? MINUTE)), 1, 5) > ?
                            LIMIT 1";
            $stmt_chk_jug = $conn->prepare($sql_chk_jug);
            $stmt_chk_jug->bind_param("isssi", $partido_id, $fechaRes, $hFinTarget, $duracion_minutos, $hIniTarget);
            $stmt_chk_jug->execute();
            $res_chk_jug = $stmt_chk_jug->get_result();
            if ($res_chk_jug && $res_chk_jug->num_rows > 0) {
                $conf_j = $res_chk_jug->fetch_assoc();
                $confUid = null;
                foreach ($jIds as $uId) {
                    if (in_array($uId, [(int)$conf_j['p1_j1'], (int)$conf_j['p1_j2'], (int)$conf_j['p2_j1'], (int)$conf_j['p2_j2']])) {
                        $confUid = $uId;
                        break;
                    }
                }
                $nomJugConf = $confUid ? ($jugadoresMap[$confUid] ?? 'Uno de los jugadores') : 'Uno de los jugadores';
                $horaConf = date('H:i', strtotime($conf_j['fecha_hora']));
                $cNom = !empty($conf_j['cancha_nom']) ? " en {$conf_j['cancha_nom']}" : "";
                $matchNom = trim(($conf_j['p1'] ?? 'Pareja 1') . ' vs ' . ($conf_j['p2'] ?? 'Pareja 2'));

                echo json_encode([
                    "success" => false,
                    "error" => "⚠️ El jugador '$nomJugConf' ya tiene programado el partido '$matchNom' el $fechaRes a las $horaConf$cNom. No puede jugar dos partidos al mismo tiempo."
                ]);
                exit;
            }

            // 6. Validar si alguno de los jugadores tiene una reserva de cancha activa
            $sql_chk_jres = "SELECT r.id, r.hora_inicio, r.hora_fin, r.usuario_id, r.jugador2_id, r.jugador3_id, r.jugador4_id,
                                    c.nombre as cancha_nom
                             FROM reservas_cancha r
                             LEFT JOIN canchas c ON r.cancha_id = c.id
                             WHERE r.fecha = ?
                               AND LOWER(r.estado) NOT IN ('cancelada', 'cancelado', 'anulada', 'anulado', 'rechazada', 'rechazado', 'eliminada', 'eliminado', 'inactiva', 'inactivo')
                               AND (r.liga_partido_id IS NULL OR r.liga_partido_id != ?)
                               AND (
                                 r.usuario_id IN ($jIdsStr) OR r.jugador2_id IN ($jIdsStr) OR
                                 r.jugador3_id IN ($jIdsStr) OR r.jugador4_id IN ($jIdsStr)
                               )
                               AND SUBSTRING(r.hora_inicio, 1, 5) < ?
                               AND (CASE 
                                      WHEN r.hora_fin IN ('00:00:00', '00:00', '24:00', '24:00:00', '23:59', '23:59:00', '23:59:59') THEN '24:00' 
                                      ELSE SUBSTRING(r.hora_fin, 1, 5) 
                                    END) > ?
                             LIMIT 1";
            $stmt_chk_jres = $conn->prepare($sql_chk_jres);
            $stmt_chk_jres->bind_param("siss", $fechaRes, $partido_id, $hFinTarget, $hIniTarget);
            $stmt_chk_jres->execute();
            $res_chk_jres = $stmt_chk_jres->get_result();
            if ($res_chk_jres && $res_chk_jres->num_rows > 0) {
                $conf_r = $res_chk_jres->fetch_assoc();
                $confUid = null;
                foreach ($jIds as $uId) {
                    if (in_array($uId, [(int)$conf_r['usuario_id'], (int)$conf_r['jugador2_id'], (int)$conf_r['jugador3_id'], (int)$conf_r['jugador4_id']])) {
                        $confUid = $uId;
                        break;
                    }
                }
                $nomJugConf = $confUid ? ($jugadoresMap[$confUid] ?? 'Uno de los jugadores') : 'Uno de los jugadores';
                $hIni = substr($conf_r['hora_inicio'], 0, 5);
                $hFin = substr($conf_r['hora_fin'], 0, 5);
                $cNom = !empty($conf_r['cancha_nom']) ? " en {$conf_r['cancha_nom']}" : "";

                echo json_encode([
                    "success" => false,
                    "error" => "⚠️ El jugador '$nomJugConf' ya tiene una reserva de cancha activa (#{$conf_r['id']}) el $fechaRes entre $hIni y $hFin$cNom."
                ]);
                exit;
            }
        }

        // Detectar si ya tenía asignación previa
        $es_reprogramacion = false;
        if (!empty($row_p['fecha_hora']) && !empty($row_p['cancha_id'])) {
            $es_reprogramacion = true;
        }

        // Eliminar reservas existentes asociadas a este partido
        $conn->query("DELETE FROM reservas_cancha WHERE liga_partido_id = $partido_id");

        // Guardar la nueva asignación
        $stmt = $conn->prepare("UPDATE liga_partidos SET cancha_id = ?, fecha_hora = ? WHERE id = ?");
        $stmt->bind_param("isi", $cancha_id, $fecha_hora, $partido_id);
        $stmt->execute();

        $c3 = $conn->query("SHOW COLUMNS FROM reservas_cancha LIKE 'nombre_externo3'");
        if ($c3 && $c3->num_rows == 0) {
            $conn->query("ALTER TABLE reservas_cancha ADD COLUMN `nombre_externo3` VARCHAR(255) NULL");
        }
        $c4 = $conn->query("SHOW COLUMNS FROM reservas_cancha LIKE 'nombre_externo4'");
        if ($c4 && $c4->num_rows == 0) {
            $conn->query("ALTER TABLE reservas_cancha ADD COLUMN `nombre_externo4` VARCHAR(255) NULL");
        }
        $cPt = $conn->query("SHOW COLUMNS FROM reservas_cancha LIKE 'precio_total'");
        if ($cPt && $cPt->num_rows == 0) {
            $conn->query("ALTER TABLE reservas_cancha ADD COLUMN `precio_total` DECIMAL(10,2) DEFAULT 0");
        }

        $stmt_res = $conn->prepare("INSERT INTO reservas_cancha (cancha_id, usuario_id, jugador2_id, jugador3_id, jugador4_id, nombre_externo, nombre_externo2, nombre_externo3, nombre_externo4, fecha, hora_inicio, hora_fin, precio, precio_total, pagado, estado, liga_partido_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 1, 'Confirmada', ?)");
        $stmt_res->bind_param("iiiiisssssssi", $cancha_id, $j1_id, $j2_id, $j3_id, $j4_id, $ext1, $ext2, $ext3, $ext4, $fechaRes, $horaIniRes, $horaFinRes, $partido_id);
        $stmt_res->execute();

        // Enviar notificación por correo y push a los jugadores involucrados
        try {
            require_once __DIR__ . '/notificaciones_liga.php';
            notificarPartidoLiga($conn, $partido_id, $es_reprogramacion ? 'reprogramado' : 'programado');
        } catch (Throwable $eNotif) {
            error_log("Error enviando notificacion partido liga: " . $eNotif->getMessage());
        }

        echo json_encode(["success" => true, "mensaje" => "Partido programado y cancha reservada correctamente"]);
    } else {
        // Dejar sin fecha / desprogramar
        $conn->query("DELETE FROM reservas_cancha WHERE liga_partido_id = $partido_id");
        $conn->query("UPDATE liga_partidos SET cancha_id = NULL, fecha_hora = NULL WHERE id = $partido_id");
        echo json_encode(["success" => true, "mensaje" => "Partido desprogramado y cancha liberada correctamente"]);
    }
} catch (Exception $e) {
    http_response_code(200);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
