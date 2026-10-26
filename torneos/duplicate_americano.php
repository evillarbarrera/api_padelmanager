<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=utf-8");

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

try {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!$data) {
        http_response_code(400);
        echo json_encode(["error" => "Datos JSON inválidos"]);
        exit;
    }

    $torneo_id = (int)($data['torneo_id'] ?? 0);
    if (!$torneo_id) {
        http_response_code(400);
        echo json_encode(["error" => "torneo_id es obligatorio"]);
        exit;
    }

    // 1. Obtener datos del torneo original
    $stmtOrig = $conn->prepare("SELECT * FROM torneos_americanos WHERE id = ?");
    $stmtOrig->bind_param("i", $torneo_id);
    $stmtOrig->execute();
    $orig = $stmtOrig->get_result()->fetch_assoc();
    $stmtOrig->close();

    if (!$orig) {
        http_response_code(404);
        echo json_encode(["error" => "Torneo original no encontrado"]);
        exit;
    }

    // 2. Parámetros para la copia
    $nombre = trim($data['nombre'] ?? '');
    if (empty($nombre)) {
        $nombre = $orig['nombre'] . ' (Copia)';
    }

    $fecha = trim($data['fecha'] ?? '');
    if (empty($fecha)) {
        $fecha = date('Y-m-d');
    }

    $hora_inicio = trim($data['hora_inicio'] ?? '');
    if (empty($hora_inicio)) {
        $hora_inicio = !empty($orig['hora_inicio']) ? substr($orig['hora_inicio'], 0, 5) : '18:00';
    }

    $creator_id = (int)($data['creator_id'] ?? 0);
    if (!$creator_id) {
        $creator_id = (int)($orig['creator_id'] ?? 0);
    }

    $club_id = (int)$orig['club_id'];
    $num_canchas = (int)($orig['num_canchas'] ?? 2);
    $tiempo_por_partido = (int)($orig['tiempo_por_partido'] ?? 20);
    $puntos_ganado = (int)($orig['puntos_ganado'] ?? 3);
    $puntos_empate = (int)($orig['puntos_empate'] ?? 1);
    $puntos_1 = (int)($orig['puntos_1er_lugar'] ?? 100);
    $puntos_2 = (int)($orig['puntos_2do_lugar'] ?? 60);
    $puntos_3 = (int)($orig['puntos_3er_lugar'] ?? 40);
    $puntos_4 = (int)($orig['puntos_4to_lugar'] ?? 20);
    $puntos_part = (int)($orig['puntos_participacion'] ?? 5);
    $categoria = $orig['categoria'] ?? 'Cuarta';
    $tipo_torneo = $orig['tipo_torneo'] ?? 'estandar';
    $modalidad = $orig['modalidad'] ?? 'unicategoria';
    $valor_suma = isset($orig['valor_suma']) ? (int)$orig['valor_suma'] : null;
    $genero = $orig['genero'] ?? null;
    $max_parejas = (int)($orig['max_parejas'] ?? 8);
    $precio = isset($orig['precio']) ? floatval($orig['precio']) : 0.00;

    // 3. Duración del torneo
    $num_c = max(1, $num_canchas);
    $t_part = max(10, $tiempo_por_partido);
    $max_p = max(4, $max_parejas);

    if ($tipo_torneo === 'grupos' && $max_p === 8) {
        $total_partidos = 14;
    } else {
        $total_partidos = ($max_p * ($max_p - 1)) / 2;
    }
    $slots = ceil($total_partidos / $num_c);
    $duracion_min = $slots * $t_part;
    $duracion_min = max(90, min(360, (int)$duracion_min));
    $duracion_min = ceil($duracion_min / 30) * 30;

    $start_ts = strtotime($hora_inicio);
    $end_ts = $start_ts + ($duracion_min * 60);
    $h_ini = date('H:i:s', $start_ts);
    $h_fin = date('H:i:s', $end_ts);
    if (date('H:i', $end_ts) === '00:00' || (date('H', $end_ts) == '00' && date('i', $end_ts) == '00')) {
        $h_fin = '24:00:00';
    }

    // 4. Determinar canchas a reservar si se enviaron o por defecto
    $canchas_a_reservar = [];
    if (!empty($data['canchas_ids']) && is_array($data['canchas_ids'])) {
        $canchas_a_reservar = array_map('intval', $data['canchas_ids']);
    } else {
        $sqlC = "SELECT id FROM canchas WHERE club_id = ? AND activa = 1 ORDER BY id ASC LIMIT ?";
        $stmtC = $conn->prepare($sqlC);
        if ($stmtC) {
            $stmtC->bind_param("ii", $club_id, $num_c);
            $stmtC->execute();
            $resC = $stmtC->get_result();
            while ($rowC = $resC->fetch_assoc()) {
                $canchas_a_reservar[] = (int)$rowC['id'];
            }
            $stmtC->close();
        }
    }

    // 5. Validar disponibilidad de canchas
    foreach ($canchas_a_reservar as $cid) {
        if ($cid > 0) {
            $stmtChk = $conn->prepare("SELECT r.id, r.hora_inicio, r.hora_fin, c.nombre FROM reservas_cancha r JOIN canchas c ON r.cancha_id = c.id WHERE r.cancha_id = ? AND r.fecha = ? AND LOWER(r.estado) NOT IN ('cancelada', 'cancelado', 'anulada', 'anulado', 'rechazada', 'rechazado', 'eliminada', 'eliminado') AND TIME(r.hora_inicio) < TIME(?) AND TIME(CASE WHEN r.hora_fin IN ('00:00:00','00:00','24:00','24:00:00','23:59','23:59:00') OR TIME(r.hora_fin) <= TIME(r.hora_inicio) THEN '24:00:00' ELSE r.hora_fin END) > TIME(?) LIMIT 1");
            if ($stmtChk) {
                $stmtChk->bind_param("isss", $cid, $fecha, $h_fin, $h_ini);
                $stmtChk->execute();
                $resChk = $stmtChk->get_result();
                if ($resChk && $rowChk = $resChk->fetch_assoc()) {
                    http_response_code(409);
                    echo json_encode([
                        "error" => "La cancha '" . $rowChk['nombre'] . "' ya se encuentra reservada el $fecha entre " . substr($rowChk['hora_inicio'], 0, 5) . " y " . substr($rowChk['hora_fin'], 0, 5) . "."
                    ]);
                    exit;
                }
                $stmtChk->close();
            }
        }
    }

    // Asegurar columnas en torneos_americanos
    $checkEst = @$conn->query("SHOW COLUMNS FROM `torneos_americanos` LIKE 'estado'");
    if ($checkEst && $checkEst->num_rows == 0) {
        @$conn->query("ALTER TABLE `torneos_americanos` ADD `estado` VARCHAR(50) DEFAULT 'Abierto'");
    }

    // 6. Insertar el nuevo torneo americano (Estado Abierto)
    $sqlIns = "INSERT INTO torneos_americanos 
            (club_id, creator_id, nombre, fecha, hora_inicio, num_canchas, tiempo_por_partido, puntos_ganado, puntos_empate, 
             puntos_1er_lugar, puntos_2do_lugar, puntos_3er_lugar, puntos_4to_lugar, puntos_participacion, categoria,
             tipo_torneo, modalidad, valor_suma, genero, max_parejas, precio, estado) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Abierto')";

    $stmtIns = $conn->prepare($sqlIns);
    if (!$stmtIns) {
        throw new Exception("Error al preparar creación de torneo: " . $conn->error);
    }

    $stmtIns->bind_param("iisssiiiiiiiiisssisid", 
        $club_id, $creator_id, $nombre, $fecha, $hora_inicio, $num_canchas, $tiempo_por_partido, 
        $puntos_ganado, $puntos_empate, $puntos_1, $puntos_2, $puntos_3, $puntos_4, $puntos_part, $categoria,
        $tipo_torneo, $modalidad, $valor_suma, $genero, $max_parejas, $precio);

    $stmtIns->execute();
    $new_torneo_id = $conn->insert_id;
    $stmtIns->close();

    // 7. Insertar reservas de bloque completo en reservas_cancha si aplica
    $chkR1 = @$conn->query("SHOW COLUMNS FROM `reservas_cancha` LIKE 'torneo_id'");
    if ($chkR1 && $chkR1->num_rows == 0) {
        @$conn->query("ALTER TABLE `reservas_cancha` ADD `torneo_id` INT NULL");
    }
    $chkR2 = @$conn->query("SHOW COLUMNS FROM `reservas_cancha` LIKE 'tipo_reserva'");
    if ($chkR2 && $chkR2->num_rows == 0) {
        @$conn->query("ALTER TABLE `reservas_cancha` ADD `tipo_reserva` VARCHAR(50) NULL");
    }
    $chkR3 = @$conn->query("SHOW COLUMNS FROM `reservas_cancha` LIKE 'nombre_externo'");
    if ($chkR3 && $chkR3->num_rows == 0) {
        @$conn->query("ALTER TABLE `reservas_cancha` ADD `nombre_externo` VARCHAR(255) NULL");
    }

    foreach ($canchas_a_reservar as $cid) {
        if ($cid > 0) {
            $sqlRes = "INSERT INTO reservas_cancha 
                      (cancha_id, usuario_id, fecha, hora_inicio, hora_fin, precio, estado, tipo_reserva, nombre_externo, torneo_id) 
                      VALUES (?, ?, ?, ?, ?, 0, 'Americano', 'americano', ?, ?)";
            $stmtRes = $conn->prepare($sqlRes);
            if ($stmtRes) {
                $stmtRes->bind_param("iissssi", $cid, $creator_id, $fecha, $h_ini, $h_fin, $nombre, $new_torneo_id);
                $stmtRes->execute();
                $stmtRes->close();
            }
        }
    }

    // 8. Copiar participantes del torneo original (SIN fixture ni resultados)
    $chkP1 = @$conn->query("SHOW COLUMNS FROM `torneo_participantes` LIKE 'check_in_1'");
    if ($chkP1 && $chkP1->num_rows == 0) {
        @$conn->query("ALTER TABLE `torneo_participantes` ADD `check_in_1` TINYINT(1) DEFAULT 0");
    }
    $chkP2 = @$conn->query("SHOW COLUMNS FROM `torneo_participantes` LIKE 'check_in_2'");
    if ($chkP2 && $chkP2->num_rows == 0) {
        @$conn->query("ALTER TABLE `torneo_participantes` ADD `check_in_2` TINYINT(1) DEFAULT 0");
    }

    $sqlPart = "SELECT jugador_id, jugador2_id, nombre_pareja, nombre_externo_1, nombre_externo_2 
                FROM torneo_participantes 
                WHERE torneo_id = ? 
                ORDER BY id ASC";
    $stmtP = $conn->prepare($sqlPart);
    $copiados = 0;
    if ($stmtP) {
        $stmtP->bind_param("i", $torneo_id);
        $stmtP->execute();
        $resPart = $stmtP->get_result();

        $sqlInsPart = "INSERT INTO torneo_participantes 
                       (torneo_id, jugador_id, jugador2_id, nombre_pareja, nombre_externo_1, nombre_externo_2, check_in_1, check_in_2) 
                       VALUES (?, ?, ?, ?, ?, ?, 0, 0)";
        $stmtInsP = $conn->prepare($sqlInsPart);

        while ($p = $resPart->fetch_assoc()) {
            $j1 = !empty($p['jugador_id']) ? (int)$p['jugador_id'] : null;
            $j2 = !empty($p['jugador2_id']) ? (int)$p['jugador2_id'] : null;
            $np = $p['nombre_pareja'];
            $ne1 = $p['nombre_externo_1'];
            $ne2 = $p['nombre_externo_2'];

            $stmtInsP->bind_param("iiisss", $new_torneo_id, $j1, $j2, $np, $ne1, $ne2);
            $stmtInsP->execute();
            $copiados++;
        }
        $stmtP->close();
        if ($stmtInsP) {
            $stmtInsP->close();
        }
    }

    echo json_encode([
        "success" => true,
        "id" => $new_torneo_id,
        "nombre" => $nombre,
        "fecha" => $fecha,
        "hora_inicio" => $hora_inicio,
        "participantes_copiados" => $copiados,
        "mensaje" => "Torneo '$nombre' creado exitosamente con $copiados parejas inscritas (sin fixture ni resultados)."
    ]);

} catch (Throwable $e) {
    error_log("Error al duplicar torneo: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "No se pudo duplicar el torneo: " . $e->getMessage()
    ]);
}
