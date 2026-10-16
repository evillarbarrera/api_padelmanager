<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type, X-Requested-With, Accept, Origin");
header("Access-Control-Max-Age: 86400");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

ob_start();
error_reporting(0);
ini_set('display_errors', 0);

try {
    require_once "../db.php";

    $data = json_decode(file_get_contents("php://input"), true);
    if (!$data) {
        $data = [];
    }

    $cancha_id = intval($data['cancha_id'] ?? 0);
    $fecha_raw = trim($data['fecha'] ?? '');
    // Normalizar formato de fecha: convertir DD/MM/YYYY o YYYY/MM/DD a YYYY-MM-DD estándar
    if (strpos($fecha_raw, '/') !== false) {
        $fParts = explode('/', $fecha_raw);
        if (count($fParts) === 3) {
            if (strlen($fParts[2]) === 4) { // DD/MM/YYYY
                $fecha_raw = sprintf('%04d-%02d-%02d', intval($fParts[2]), intval($fParts[1]), intval($fParts[0]));
            } else if (strlen($fParts[0]) === 4) { // YYYY/MM/DD
                $fecha_raw = sprintf('%04d-%02d-%02d', intval($fParts[0]), intval($fParts[1]), intval($fParts[2]));
            }
        }
    } else if (strpos($fecha_raw, 'T') !== false) {
        $fecha_raw = explode('T', $fecha_raw)[0];
    }
    $fecha = $fecha_raw;
    $hora_inicio = $data['hora_inicio'] ?? '';
    $duracion = intval($data['duracion'] ?? 90);
    $metodo_pago = strtolower(trim($data['metodo_pago'] ?? 'total'));
    $pagos = $data['pagos'] ?? [];

    // Leer pagos (prioridad a los campos aplanados)
    $pago_p1 = isset($data['pago_p1']) ? intval($data['pago_p1']) : (isset($pagos['p1']) && $pagos['p1'] ? 1 : 0);
    $pago_p2 = isset($data['pago_p2']) ? intval($data['pago_p2']) : (isset($pagos['p2']) && $pagos['p2'] ? 1 : 0);
    $pago_p3 = isset($data['pago_p3']) ? intval($data['pago_p3']) : (isset($pagos['p3']) && $pagos['p3'] ? 1 : 0);
    $pago_p4 = isset($data['pago_p4']) ? intval($data['pago_p4']) : (isset($pagos['p4']) && $pagos['p4'] ? 1 : 0);

    // Extraer jugadores (usar null si es 0 para evitar fallos de Foreign Key)
    $jugador_id = !empty($data['jugador_id']) ? intval($data['jugador_id']) : null;
    $jugador2_id = !empty($data['jugador2_id']) ? intval($data['jugador2_id']) : null;
    $jugador3_id = !empty($data['jugador3_id']) ? intval($data['jugador3_id']) : null;
    $jugador4_id = !empty($data['jugador4_id']) ? intval($data['jugador4_id']) : null;

    $nombre_externo = $data['nombre_externo'] ?? '';
    $nombre_externo2 = $data['nombre_externo2'] ?? '';
    $nombre_externo3 = $data['nombre_externo3'] ?? '';
    $nombre_externo4 = $data['nombre_externo4'] ?? '';

    if (!$cancha_id || !$fecha || !$hora_inicio) {
        @ob_clean();
        http_response_code(400);
        echo json_encode(["error" => "Faltan datos obligatorios"]);
        exit;
    }

    // 1. Limpiar reservas huérfanas de liga desincronizadas (partido borrado, sin fecha/cancha, o reprogramado a otra fecha/cancha)
    @$conn->query("DELETE r FROM reservas_cancha r 
                   LEFT JOIN liga_partidos lp ON r.liga_partido_id = lp.id 
                   WHERE r.liga_partido_id IS NOT NULL 
                     AND r.liga_partido_id > 0
                     AND (lp.id IS NULL OR lp.fecha_hora IS NULL OR lp.fecha_hora LIKE '0000%' OR lp.cancha_id IS NULL 
                          OR DATE(lp.fecha_hora) != r.fecha OR lp.cancha_id != r.cancha_id)");

    // 2. Limpiar reservas huérfanas o desincronizadas de torneos americanos
    @$conn->query("DELETE r FROM reservas_cancha r 
                   LEFT JOIN torneos_americanos ta ON r.torneo_id = ta.id 
                   WHERE r.torneo_id IS NOT NULL 
                     AND r.torneo_id > 0 
                     AND (ta.id IS NULL OR ta.estado = 'Cerrado' OR r.fecha != ta.fecha)");

    @$conn->query("DELETE r FROM reservas_cancha r
                   JOIN canchas c ON r.cancha_id = c.id
                   JOIN torneos_americanos ta ON ta.club_id = c.club_id AND ta.nombre = r.nombre_externo
                   WHERE (r.tipo_reserva = 'americano' OR r.estado = 'Americano')
                     AND r.fecha != ta.fecha");

    // 3. Limpiar reservas duplicadas idénticas activas en la misma cancha y fecha
    @$conn->query("DELETE r1 FROM reservas_cancha r1
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

    // 4. Auto-reparar reservas normales que fueron erróneamente guardadas con hora_fin 24:00:00 o 00:00:00
    @$conn->query("UPDATE reservas_cancha 
                   SET hora_fin = ADDTIME(hora_inicio, '01:30:00') 
                   WHERE cancha_id = $cancha_id AND fecha = '$fecha'
                     AND (hora_fin LIKE '24:00%' OR hora_fin = '00:00:00' OR hora_fin IS NULL)
                     AND hora_inicio != '00:00:00'
                     AND estado != 'Bloqueada'");

    // Normalizar hora_inicio a formato HH:MM:SS de 8 caracteres
    $hIniRaw = trim($hora_inicio);
    if (preg_match('/^(\d{1,2}):(\d{2})(:(\d{2}))?$/', $hIniRaw, $mMatches)) {
        $hVal = str_pad($mMatches[1], 2, '0', STR_PAD_LEFT);
        $minVal = $mMatches[2];
        $secVal = isset($mMatches[4]) ? $mMatches[4] : '00';
        $hora_inicio_clean = "$hVal:$minVal:$secVal";
    } else {
        $hora_inicio_clean = '08:00:00';
    }
    $hora_inicio = $hora_inicio_clean;

    // Inicializar estado antes del cálculo de horarios
    $estado = $data['estado'] ?? 'Confirmada';

    // Calcular hora_fin con base en duración si no es bloqueo manual de rango personalizado
    $duracion = intval($data['duracion'] ?? 90);
    if ($duracion <= 0) $duracion = 90;

    $hora_fin_raw = trim($data['hora_fin'] ?? '');
    if (preg_match('/^(\d{1,2}):(\d{2})(:(\d{2}))?$/', $hora_fin_raw, $mMatchesFin)) {
        $hFVal = str_pad($mMatchesFin[1], 2, '0', STR_PAD_LEFT);
        $minFVal = $mMatchesFin[2];
        $secFVal = isset($mMatchesFin[4]) ? $mMatchesFin[4] : '00';
        $hora_fin_clean = "$hFVal:$minFVal:$secFVal";
    } else {
        $hora_fin_clean = '';
    }

    // Calcular segundos desde medianoche con aritmética pura
    $start_sec = (intval(substr($hora_inicio_clean, 0, 2)) * 3600) + (intval(substr($hora_inicio_clean, 3, 2)) * 60) + intval(substr($hora_inicio_clean, 6, 2));

    if (empty($hora_fin_clean) || ($estado !== 'Bloqueada' && !empty($duracion))) {
        $end_sec = $start_sec + ($duracion * 60);
        if ($end_sec >= 86400) {
            $hora_fin = "24:00:00";
            $end_sec = 86400;
        } else {
            $hEnd = floor($end_sec / 3600);
            $mEnd = floor(($end_sec % 3600) / 60);
            $sEnd = $end_sec % 60;
            $hora_fin = sprintf('%02d:%02d:%02d', $hEnd, $mEnd, $sEnd);
        }
    } else {
        $hora_fin = $hora_fin_clean;
        $end_sec = ($hora_fin === '24:00:00' || $hora_fin === '24:00' || $hora_fin === '23:59' || $hora_fin === '23:59:00' || $hora_fin === '23:59:59')
            ? 86400
            : ((intval(substr($hora_fin, 0, 2)) * 3600) + (intval(substr($hora_fin, 3, 2)) * 60) + intval(substr($hora_fin, 6, 2)));
    }

    $hora_fin_check = ($hora_fin === '00:00:00' || $hora_fin === '00:00' || $hora_fin === '24:00' || $hora_fin === '23:59' || $hora_fin === '23:59:00' || $hora_fin === '23:59:59') ? '24:00:00' : trim($hora_fin);
    if (strlen($hora_fin_check) === 5) {
        $hora_fin_check .= ':00';
    }

    // Iniciar transacción atómica y bloqueo para prevenir colisiones concurrentes (Race Conditions)
    $conn->begin_transaction();

    // Bloqueo pesimista a nivel de cancha
    $lockStmt = $conn->prepare("SELECT id FROM canchas WHERE id = ? FOR UPDATE");
    if ($lockStmt) {
        $lockStmt->bind_param("i", $cancha_id);
        $lockStmt->execute();
        $lockStmt->close();
    }

    // 1. Validar disponibilidad con comparación estricta de segundos TIME_TO_SEC()
    $sqlCheck = "SELECT id, hora_inicio, hora_fin FROM reservas_cancha 
                 WHERE cancha_id = ? AND fecha = ? 
                 AND TIME_TO_SEC(hora_inicio) < ? 
                 AND (CASE 
                     WHEN hora_fin LIKE '24:00%' OR hora_fin LIKE '23:59%' THEN 86400
                     WHEN (hora_fin = '00:00:00' OR hora_fin = '00:00') AND hora_inicio > '00:00:00' THEN 86400
                     ELSE TIME_TO_SEC(hora_fin) 
                 END) > ?
                 AND TRIM(LOWER(estado)) NOT IN ('cancelada', 'cancelado', 'anulada', 'anulado', 'rechazada', 'rechazado', 'eliminada', 'eliminado', 'inactiva', 'inactivo', 'expirada', 'expirado')
                 AND estado IS NOT NULL
                 FOR UPDATE";
    $stmtCheck = $conn->prepare($sqlCheck);
    if ($stmtCheck) {
        $stmtCheck->bind_param("isii", $cancha_id, $fecha, $end_sec, $start_sec);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();
        if ($resCheck && $resCheck->num_rows > 0) {
            $confRow = $resCheck->fetch_assoc();
            $conn->rollback();
            @ob_clean();
            http_response_code(409);
            echo json_encode([
                "error" => "La cancha ya está reservada en ese horario (" . substr($confRow['hora_inicio'], 0, 5) . " - " . substr($confRow['hora_fin'], 0, 5) . ")"
            ]);
            exit;
        }
        $stmtCheck->close();
    }

    // Detectar si la reserva es de un partido de liga (los partidos de liga parten como pagados por la inscripción)
    $liga_partido_id = !empty($data['liga_partido_id']) ? intval($data['liga_partido_id']) : null;
    $is_liga = ($liga_partido_id !== null && $liga_partido_id > 0) || (!empty($data['tipo_reserva']) && $data['tipo_reserva'] === 'liga');

    if ($is_liga) {
        $pagado = 1;
        $pago_p1 = 1;
        $pago_p2 = 1;
        $pago_p3 = 1;
        $pago_p4 = 1;
    } else {
        // Toda reserva normal parte como no pagada (0) por defecto
        $pagado = intval($data['pagado'] ?? 0);
        if ($metodo_pago === 'proporcional') {
            $allPaid = ($pago_p1 == 1);
            if (!empty($nombre_externo2) || !empty($jugador2_id)) $allPaid = $allPaid && ($pago_p2 == 1);
            if (!empty($nombre_externo3) || !empty($jugador3_id)) $allPaid = $allPaid && ($pago_p3 == 1);
            if (!empty($nombre_externo4) || !empty($jugador4_id)) $allPaid = $allPaid && ($pago_p4 == 1);
            $pagado = $allPaid ? 1 : 0;
        }
    }

    $estado = $data['estado'] ?? 'Confirmada';

    // Purgar cualquier reserva cancelada o inactiva en este mismo slot exacto para evitar conflictos de duplicado
    @$conn->query("DELETE FROM reservas_cancha 
                   WHERE cancha_id = $cancha_id AND fecha = '$fecha' AND hora_inicio = '$hora_inicio' 
                     AND TRIM(LOWER(estado)) IN ('cancelada', 'cancelado', 'anulada', 'anulado', 'rechazada', 'eliminada')");

    $sqlInsert = "INSERT INTO reservas_cancha 
                  (cancha_id, usuario_id, jugador2_id, jugador3_id, jugador4_id, 
                   nombre_externo, nombre_externo2, nombre_externo3, nombre_externo4, 
                   fecha, hora_inicio, hora_fin, precio, pagado, estado, metodo_pago, pago_p1, pago_p2, pago_p3, pago_p4, liga_partido_id, torneo_id) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)";

    $stmt = $conn->prepare($sqlInsert);
    if (!$stmt) {
        @ob_clean();
        http_response_code(500);
        echo json_encode(["error" => "Error preparando SQL: " . $conn->error]);
        exit;
    }

    $precio_cancha = intval(round(floatval($data['precio'] ?? 0)));
    $stmt->bind_param(
        "iiiiisssssssdissiiiii",
        $cancha_id,
        $jugador_id,
        $jugador2_id,
        $jugador3_id,
        $jugador4_id,
        $nombre_externo,
        $nombre_externo2,
        $nombre_externo3,
        $nombre_externo4,
        $fecha,
        $hora_inicio,
        $hora_fin,
        $precio_cancha,
        $pagado,
        $estado,
        $metodo_pago,
        $pago_p1,
        $pago_p2,
        $pago_p3,
        $pago_p4,
        $liga_partido_id
    );

    if ($stmt->execute()) {
        $newId = $conn->insert_id;
        
        // --- INTEGRACIÓN CON INVENTARIO Y VENTAS SI SE CREA PAGADA ---
        if (intval($pagado) === 1 && $precio_cancha > 0) {
            try {
                $clubRow = $conn->query("SELECT club_id FROM canchas WHERE id = $cancha_id")->fetch_assoc();
                $club_id = $clubRow ? intval($clubRow['club_id']) : 0;

                if ($club_id > 0) {
                    $metodo_pago_v = ($metodo_pago == 'proporcional') ? 'Varios' : (($metodo_pago == 'total' || empty($metodo_pago)) ? 'Reserva' : ucfirst(strtolower($metodo_pago)));
                    $vendedor_id = !empty($data['vendedor_id']) ? intval($data['vendedor_id']) : (!empty($data['usuario_vendedor_id']) ? intval($data['usuario_vendedor_id']) : 0);
                    $cliente_id = intval($jugador_id ?? 0);

                    $checkColJ = $conn->query("SHOW COLUMNS FROM inventario_ventas LIKE 'jugador_id'");
                    if ($checkColJ && $checkColJ->num_rows == 0) {
                        $conn->query("ALTER TABLE inventario_ventas ADD `jugador_id` INT NULL");
                    }

                    $hInicioClean = !empty($hora_inicio) ? (strlen($hora_inicio) === 5 ? $hora_inicio . ':00' : $hora_inicio) : '12:00:00';
                    $fechaVenta = !empty($fecha) ? ($fecha . ' ' . $hInicioClean) : date('Y-m-d H:i:s');
                    $stmtVenta = $conn->prepare("INSERT INTO inventario_ventas (club_id, usuario_id, jugador_id, total, metodo_pago, reserva_id, fecha) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    if ($stmtVenta) {
                        $stmtVenta->bind_param("iiidsis", $club_id, $vendedor_id, $cliente_id, $precio_cancha, $metodo_pago_v, $newId, $fechaVenta);
                        if ($stmtVenta->execute()) {
                            $venta_id = $conn->insert_id;
                            $stmtDetCancha = $conn->prepare("INSERT INTO inventario_venta_detalles (venta_id, producto_id, cantidad, precio_unitario) VALUES (?, 0, 1, ?)");
                            if ($stmtDetCancha) {
                                $stmtDetCancha->bind_param("id", $venta_id, $precio_cancha);
                                $stmtDetCancha->execute();
                                $stmtDetCancha->close();
                            }
                            $conn->query("UPDATE reservas_cancha SET venta_generada = 1 WHERE id = $newId");
                        }
                        $stmtVenta->close();
                    }
                }
            } catch (Throwable $e) {
                error_log("Error generando venta inicial para reserva #$newId: " . $e->getMessage());
            }
        }

        $conn->commit();

        // Devolver respuesta JSON limpia
        @ob_clean();
        echo json_encode([
            "success" => true,
            "id" => $newId,
            "reserva_id" => $newId,
            "cancha_id" => $cancha_id,
            "fecha" => $fecha,
            "hora_inicio" => $hora_inicio,
            "hora_fin" => $hora_fin,
            "duracion" => $duracion,
            "estado" => $estado
        ]);

        // Procesar notificación en segundo plano
        try {
            if (file_exists("../system/mail_service.php")) {
                require_once "../system/mail_service.php";
                if (function_exists('notificarReservaCancha')) {
                    notificarReservaCancha($conn, $newId, 'creacion');
                }
            }
        } catch (Throwable $eMail) {
            error_log("Error enviando notificacion de reserva: " . $eMail->getMessage());
        }
    } else {
        @$conn->rollback();
        @ob_clean();
        http_response_code(500);
        echo json_encode(["error" => "Error al guardar la reserva: " . $conn->error]);
    }
} catch (Throwable $eGlobal) {
    if (isset($conn) && $conn) {
        @$conn->rollback();
    }
    @ob_clean();
    http_response_code(500);
    echo json_encode(["error" => "Excepción en el servidor: " . $eGlobal->getMessage()]);
}
?>
