<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Max-Age: 3600");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', 0);
error_reporting(E_ALL);

try {
    include_once '../db.php';

    if (!isset($conn) || $conn->connect_error) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error de conexión a base de datos"]);
        exit();
    }

    $rawInput = file_get_contents("php://input");
    $data = json_decode($rawInput, true);

    if (!$data || empty($data['id'])) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "ID de torneo no proporcionado"]);
        exit();
    }

    $id = (int)$data['id'];

    // Helper para verificar y agregar columnas sin lanzar errores
    function ensureColumnExists($conn, $table, $column, $definition) {
        try {
            $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
            if ($check && $check->num_rows == 0) {
                $conn->query("ALTER TABLE `$table` ADD `$column` $definition");
            }
        } catch (Throwable $t) {
            // Ignorar errores menores de schema
        }
    }

    ensureColumnExists($conn, 'torneos_americanos', 'precio', "DECIMAL(10,2) DEFAULT 0.00");
    ensureColumnExists($conn, 'torneos_americanos', 'puntos_1er_lugar', "INT DEFAULT 100");
    ensureColumnExists($conn, 'torneos_americanos', 'puntos_2do_lugar', "INT DEFAULT 60");
    ensureColumnExists($conn, 'torneos_americanos', 'puntos_3er_lugar', "INT DEFAULT 40");
    ensureColumnExists($conn, 'torneos_americanos', 'puntos_4to_lugar', "INT DEFAULT 20");
    ensureColumnExists($conn, 'torneos_americanos', 'puntos_participacion', "INT DEFAULT 5");
    ensureColumnExists($conn, 'reservas_cancha', 'torneo_id', "INT NULL");
    ensureColumnExists($conn, 'reservas_cancha', 'tipo_reserva', "VARCHAR(50) NULL");
    ensureColumnExists($conn, 'reservas_cancha', 'nombre_externo', "VARCHAR(255) NULL");

    // 1. Obtener datos previos del torneo
    $sqlPrev = "SELECT * FROM torneos_americanos WHERE id = ?";
    $stmtPrev = $conn->prepare($sqlPrev);
    if (!$stmtPrev) {
        throw new Exception("Error al preparar consulta de torneo: " . $conn->error);
    }
    $stmtPrev->bind_param("i", $id);
    $stmtPrev->execute();
    $prevTorneo = $stmtPrev->get_result()->fetch_assoc();
    $stmtPrev->close();

    if (!$prevTorneo) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Torneo no encontrado"]);
        exit();
    }

    $updates = [];
    $types = "";
    $params = [];

    if (isset($data['nombre']) && $data['nombre'] !== '') {
        $updates[] = "nombre = ?";
        $types .= "s";
        $params[] = $data['nombre'];
    }

    if (!empty($data['fecha'])) {
        $updates[] = "fecha = ?";
        $types .= "s";
        $params[] = $data['fecha'];
    }

    if (!empty($data['hora_inicio'])) {
        $updates[] = "hora_inicio = ?";
        $types .= "s";
        $params[] = $data['hora_inicio'];
    }

    if (isset($data['precio'])) {
        $updates[] = "precio = ?";
        $types .= "d";
        $params[] = floatval($data['precio']);
    }

    if (isset($data['tiempo_por_partido'])) {
        $updates[] = "tiempo_por_partido = ?";
        $types .= "i";
        $params[] = (int)$data['tiempo_por_partido'];
    }

    if (isset($data['num_canchas'])) {
        $updates[] = "num_canchas = ?";
        $types .= "i";
        $params[] = (int)$data['num_canchas'];
    }

    if (isset($data['max_parejas'])) {
        $updates[] = "max_parejas = ?";
        $types .= "i";
        $params[] = (int)$data['max_parejas'];
    }

    if (isset($data['puntos_1er_lugar'])) {
        $updates[] = "puntos_1er_lugar = ?";
        $types .= "i";
        $params[] = (int)$data['puntos_1er_lugar'];
    }

    if (isset($data['puntos_2do_lugar'])) {
        $updates[] = "puntos_2do_lugar = ?";
        $types .= "i";
        $params[] = (int)$data['puntos_2do_lugar'];
    }

    if (isset($data['puntos_3er_lugar'])) {
        $updates[] = "puntos_3er_lugar = ?";
        $types .= "i";
        $params[] = (int)$data['puntos_3er_lugar'];
    }

    if (isset($data['puntos_4to_lugar'])) {
        $updates[] = "puntos_4to_lugar = ?";
        $types .= "i";
        $params[] = (int)$data['puntos_4to_lugar'];
    }

    if (isset($data['puntos_participacion'])) {
        $updates[] = "puntos_participacion = ?";
        $types .= "i";
        $params[] = (int)$data['puntos_participacion'];
    }

    if (!empty($updates)) {
        $sql = "UPDATE torneos_americanos SET " . implode(", ", $updates) . " WHERE id = ?";
        $types .= "i";
        $params[] = $id;

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare UPDATE failed: " . $conn->error);
        }

        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            throw new Exception("Execute UPDATE failed: " . $stmt->error);
        }
        $stmt->close();
    }

    // Obtener datos actualizados del torneo
    $sqlT = "SELECT * FROM torneos_americanos WHERE id = ?";
    $stmtT = $conn->prepare($sqlT);
    $stmtT->bind_param("i", $id);
    $stmtT->execute();
    $torneoUp = $stmtT->get_result()->fetch_assoc();
    $stmtT->close();

    $fechaCambio = ($prevTorneo['fecha'] !== $torneoUp['fecha']);
    $horaCambio = ($prevTorneo['hora_inicio'] !== $torneoUp['hora_inicio']);
    $canchasCambio = ($prevTorneo['num_canchas'] != $torneoUp['num_canchas']);

    if ($torneoUp && $torneoUp['estado'] !== 'Cerrado') {
        // 1. Borrar todas las reservas previas asociadas al torneo (por torneo_id y por nombre en canchas del club)
        $conn->query("DELETE FROM reservas_cancha WHERE torneo_id = $id");

        $prevNom = $conn->real_escape_string($prevTorneo['nombre']);
        $cid_club = (int)$torneoUp['club_id'];
        $conn->query("DELETE r FROM reservas_cancha r 
                      JOIN canchas c ON r.cancha_id = c.id 
                      WHERE c.club_id = $cid_club 
                        AND r.nombre_externo = '$prevNom' 
                        AND (r.tipo_reserva = 'americano' OR r.estado = 'Americano')");

        // 2. Si la fecha o la hora cambiaron, borrar fixture/partidos previos para forzar regeneración
        $fixtureBorrado = false;
        if ($fechaCambio || $horaCambio || $canchasCambio || !empty($data['clear_fixture'])) {
            try {
                $checkT = $conn->query("SHOW TABLES LIKE 'torneo_partidos'");
                if ($checkT && $checkT->num_rows > 0) {
                    $conn->query("DELETE FROM torneo_partidos WHERE torneo_id = $id");
                }
            } catch (Throwable $t) {}

            try {
                $checkV2 = $conn->query("SHOW TABLES LIKE 'torneo_partidos_v2'");
                if ($checkV2 && $checkV2->num_rows > 0) {
                    $conn->query("DELETE FROM torneo_partidos_v2 WHERE torneo_id = $id");
                }
            } catch (Throwable $t) {}

            $fixtureBorrado = true;
        }

        // 3. Re-crear reservas de canchas en la NUEVA fecha y horarios
        $num_c = max(1, (int)$torneoUp['num_canchas']);
        $t_part = max(10, (int)$torneoUp['tiempo_por_partido']);
        $max_p = max(4, (int)$torneoUp['max_parejas']);
        $tipo_t = $torneoUp['tipo_torneo'] ?? 'estandar';

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

        $start_ts = strtotime($torneoUp['hora_inicio']);
        $end_ts = $start_ts + ($duracion_min * 60);
        $h_ini = date('H:i:s', $start_ts);
        $h_fin = date('H:i:s', $end_ts);
        if (date('H:i', $end_ts) === '00:00' || (date('H', $end_ts) == '00' && date('i', $end_ts) == '00')) {
            $h_fin = '24:00:00';
        }

        // Canchas a reservar
        $canchas_a_reservar = [];
        if (!empty($data['canchas_ids']) && is_array($data['canchas_ids'])) {
            $canchas_a_reservar = array_map('intval', $data['canchas_ids']);
        } else {
            $sqlC = "SELECT id FROM canchas WHERE club_id = ? AND (activa = 1 OR activa IS NULL) ORDER BY id ASC LIMIT ?";
            $stmtC = $conn->prepare($sqlC);
            if ($stmtC) {
                $cid_club = (int)$torneoUp['club_id'];
                $stmtC->bind_param("ii", $cid_club, $num_c);
                $stmtC->execute();
                $resC = $stmtC->get_result();

                while ($rowC = $resC->fetch_assoc()) {
                    $canchas_a_reservar[] = (int)$rowC['id'];
                }
                $stmtC->close();
            }
        }

        $fec = $torneoUp['fecha'];

        // Validar colisiones con reservas de otros usuarios
        foreach ($canchas_a_reservar as $c_id) {
            if ($c_id > 0) {
                $stmtChk = $conn->prepare("SELECT r.id, r.hora_inicio, r.hora_fin, c.nombre FROM reservas_cancha r JOIN canchas c ON r.cancha_id = c.id WHERE r.cancha_id = ? AND r.fecha = ? AND (r.torneo_id IS NULL OR r.torneo_id != ?) AND LOWER(r.estado) NOT IN ('cancelada', 'cancelado', 'anulada', 'anulado', 'rechazada', 'rechazado', 'eliminada', 'eliminado') AND TIME(r.hora_inicio) < TIME(?) AND TIME(CASE WHEN r.hora_fin IN ('00:00:00','00:00','24:00','24:00:00','23:59','23:59:00') OR TIME(r.hora_fin) <= TIME(r.hora_inicio) THEN '24:00:00' ELSE r.hora_fin END) > TIME(?) LIMIT 1");
                if ($stmtChk) {
                    $stmtChk->bind_param("iisss", $c_id, $fec, $id, $h_fin, $h_ini);
                    $stmtChk->execute();
                    $resChk = $stmtChk->get_result();
                    if ($resChk && $rowChk = $resChk->fetch_assoc()) {
                        http_response_code(409);
                        echo json_encode([
                            "error" => "La cancha '" . $rowChk['nombre'] . "' ya se encuentra reservada el $fec entre " . substr($rowChk['hora_inicio'], 0, 5) . " y " . substr($rowChk['hora_fin'], 0, 5) . "."
                        ]);
                        exit;
                    }
                    $stmtChk->close();
                }
            }
        }

        foreach ($canchas_a_reservar as $c_id) {
            if ($c_id > 0) {
                $sqlRes = "INSERT INTO reservas_cancha 
                          (cancha_id, usuario_id, fecha, hora_inicio, hora_fin, precio, precio_total, pagado, estado, tipo_reserva, nombre_externo, torneo_id) 
                          VALUES (?, ?, ?, ?, ?, 0, 0, 0, 'Americano', 'americano', ?, ?)";
                $stmtRes = $conn->prepare($sqlRes);
                if ($stmtRes) {
                    $nom = $torneoUp['nombre'];
                    $creator = (int)($torneoUp['creator_id'] ?? 0);
                    $stmtRes->bind_param("iissssi", $c_id, $creator, $fec, $h_ini, $h_fin, $nom, $id);
                    $stmtRes->execute();
                    $stmtRes->close();
                }
            }
        }
    }

    echo json_encode([
        "status" => "success",
        "message" => "Torneo Americano actualizado exitosamente",
        "fecha_cambiada" => $fechaCambio,
        "fixture_reiniciado" => $fixtureBorrado ?? false,
        "nueva_fecha" => $torneoUp['fecha'],
        "nuevo_horario" => $torneoUp['hora_inicio']
    ]);

    $conn->close();

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Error interno: " . $e->getMessage(),
        "line" => $e->getLine()
    ]);
}
?>
