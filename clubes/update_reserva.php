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

    $id = intval($data['id'] ?? 0);
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
    $hora_fin = $data['hora_fin'] ?? '';
    $estado = $data['estado'] ?? 'Confirmada';

    $metodo_pago = strtolower(trim($data['metodo_pago'] ?? 'total'));
    $pagos = $data['pagos'] ?? [];

    // Leer pagos (prioridad a los campos aplanados enviados desde el front)
    $pago_p1 = isset($data['pago_p1']) ? intval($data['pago_p1']) : (isset($pagos['p1']) && $pagos['p1'] ? 1 : 0);
    $pago_p2 = isset($data['pago_p2']) ? intval($data['pago_p2']) : (isset($pagos['p2']) && $pagos['p2'] ? 1 : 0);
    $pago_p3 = isset($data['pago_p3']) ? intval($data['pago_p3']) : (isset($pagos['p3']) && $pagos['p3'] ? 1 : 0);
    $pago_p4 = isset($data['pago_p4']) ? intval($data['pago_p4']) : (isset($pagos['p4']) && $pagos['p4'] ? 1 : 0);

    // Extraer jugadores con seguridad (usar null si es 0 para evitar fallos de Foreign Key)
    $jugador_id = !empty($data['jugador_id']) ? intval($data['jugador_id']) : null;
    $jugador2_id = !empty($data['jugador2_id']) ? intval($data['jugador2_id']) : null;
    $jugador3_id = !empty($data['jugador3_id']) ? intval($data['jugador3_id']) : null;
    $jugador4_id = !empty($data['jugador4_id']) ? intval($data['jugador4_id']) : null;

    $nombre_externo = $data['nombre_externo'] ?? '';
    $nombre_externo2 = $data['nombre_externo2'] ?? '';
    $nombre_externo3 = $data['nombre_externo3'] ?? '';
    $nombre_externo4 = $data['nombre_externo4'] ?? '';

    // Si la reserva corresponde a un partido de liga, siempre debe mantenerse pagada (la liga se paga por inscripción)
    $is_liga = false;
    $resCheckLiga = $conn->query("SELECT liga_partido_id FROM reservas_cancha WHERE id = $id");
    if ($resCheckLiga && $rowCL = $resCheckLiga->fetch_assoc()) {
        if (!empty($rowCL['liga_partido_id']) && intval($rowCL['liga_partido_id']) > 0) {
            $is_liga = true;
        }
    }
    if (!empty($data['liga_partido_id']) && intval($data['liga_partido_id']) > 0) {
        $is_liga = true;
    }

    if ($is_liga) {
        $pagado = 1;
        $pago_p1 = 1;
        $pago_p2 = 1;
        $pago_p3 = 1;
        $pago_p4 = 1;
    } else {
        $pagado = intval($data['pagado'] ?? 0);
        if ($metodo_pago === 'proporcional') {
            $allPaid = ($pago_p1 == 1);
            if (!empty($data['nombre_externo2']) || !empty($data['jugador2_id'])) $allPaid = $allPaid && ($pago_p2 == 1);
            if (!empty($data['nombre_externo3']) || !empty($data['jugador3_id'])) $allPaid = $allPaid && ($pago_p3 == 1);
            if (!empty($data['nombre_externo4']) || !empty($data['jugador4_id'])) $allPaid = $allPaid && ($pago_p4 == 1);
            $pagado = $allPaid ? 1 : 0;
        }
    }

    if (!$id || !$cancha_id || !$fecha || !$hora_inicio) {
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
                   WHERE cancha_id = $cancha_id AND fecha = '$fecha' AND id != $id
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

    // Calcular segundos desde medianoche con aritmética pura para evitar desajustes de zona horaria en strtotime
    $start_sec = (intval(substr($hora_inicio_clean, 0, 2)) * 3600) + (intval(substr($hora_inicio_clean, 3, 2)) * 60) + intval(substr($hora_inicio_clean, 6, 2));

    if (empty($hora_fin_clean) || ($estado !== 'Bloqueada' && !empty($data['duracion']))) {
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

    // Iniciar transacción y bloqueo pesimista
    $conn->begin_transaction();

    $lockStmt = $conn->prepare("SELECT id FROM canchas WHERE id = ? FOR UPDATE");
    if ($lockStmt) {
        $lockStmt->bind_param("i", $cancha_id);
        $lockStmt->execute();
        $lockStmt->close();
    }

    // Obtener datos actuales de la reserva antes de actualizar
    $currResStmt = $conn->prepare("SELECT id, cancha_id, fecha, hora_inicio, hora_fin, torneo_id, liga_partido_id, usuario_id, jugador2_id, jugador3_id, jugador4_id FROM reservas_cancha WHERE id = ?");
    $currTorneoId = 0;
    $currLigaPartidoId = 0;
    $currCanchaId = 0;
    $currFecha = '';
    $currHoraInicio = '';
    $currHoraFin = '';
    $oldPlayerIds = [];
    if ($currResStmt) {
        $currResStmt->bind_param("i", $id);
        $currResStmt->execute();
        $currRes = $currResStmt->get_result()->fetch_assoc();
        $currResStmt->close();
        if ($currRes) {
            $currCanchaId = intval($currRes['cancha_id'] ?? 0);
            $currFecha = trim($currRes['fecha'] ?? '');
            $currHoraInicio = trim($currRes['hora_inicio'] ?? '');
            $currHoraFin = trim($currRes['hora_fin'] ?? '');
            $currTorneoId = intval($currRes['torneo_id'] ?? 0);
            $currLigaPartidoId = intval($currRes['liga_partido_id'] ?? 0);
            if (!empty($currRes['usuario_id'])) $oldPlayerIds[] = intval($currRes['usuario_id']);
            if (!empty($currRes['jugador2_id'])) $oldPlayerIds[] = intval($currRes['jugador2_id']);
            if (!empty($currRes['jugador3_id'])) $oldPlayerIds[] = intval($currRes['jugador3_id']);
            if (!empty($currRes['jugador4_id'])) $oldPlayerIds[] = intval($currRes['jugador4_id']);
        }
    }

    // Comprobar si el horario, fecha o cancha realmente cambiaron respecto a lo que ya está guardado
    $isCanchaChanged = ($currCanchaId > 0 && $currCanchaId !== $cancha_id);
    $isFechaChanged = (!empty($currFecha) && $currFecha !== $fecha);

    $currHIniClean = substr(trim($currHoraInicio), 0, 5);
    $newHIniClean = substr(trim($hora_inicio_clean), 0, 5);
    $isHoraIniChanged = (!empty($currHIniClean) && $currHIniClean !== $newHIniClean);

    $currHFinClean = substr(trim($currHoraFin), 0, 5);
    $newHFinClean = substr(trim($hora_fin_check), 0, 5);
    $isHoraFinChanged = (!empty($currHFinClean) && $currHFinClean !== $newHFinClean);

    $isTimeChanged = ($isCanchaChanged || $isFechaChanged || $isHoraIniChanged || $isHoraFinChanged);

    // 1. Validar disponibilidad SÓLO SI el horario, fecha o cancha fueron modificados
    if ($isTimeChanged) {
        $sqlCheck = "SELECT id, hora_inicio, hora_fin FROM reservas_cancha 
                     WHERE cancha_id = ? AND fecha = ? AND id != ?
                     AND (? = 0 OR torneo_id IS NULL OR torneo_id != ?)
                     AND (? = 0 OR liga_partido_id IS NULL OR liga_partido_id != ?)
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
            $stmtCheck->bind_param("isiiiiiii", $cancha_id, $fecha, $id, $currTorneoId, $currTorneoId, $currLigaPartidoId, $currLigaPartidoId, $end_sec, $start_sec);
            $stmtCheck->execute();
            $resCheck = $stmtCheck->get_result();
            if ($resCheck && $resCheck->num_rows > 0) {
                $confRow = $resCheck->fetch_assoc();
                $conn->rollback();
                @ob_clean();
                http_response_code(409);
                echo json_encode([
                    "error" => "Choque de horario con otra reserva (" . substr($confRow['hora_inicio'], 0, 5) . " - " . substr($confRow['hora_fin'], 0, 5) . ")"
                ]);
                exit;
            }
            $stmtCheck->close();
        }
    }

    // 2. Actualizar reserva
    $sqlUpdate = "UPDATE reservas_cancha SET 
                  cancha_id = ?, 
                  usuario_id = ?, jugador2_id = ?, jugador3_id = ?, jugador4_id = ?, 
                  nombre_externo = ?, nombre_externo2 = ?, nombre_externo3 = ?, nombre_externo4 = ?, 
                  fecha = ?, hora_inicio = ?, hora_fin = ?, precio = ?, pagado = ?, estado = ?,
                  metodo_pago = ?, pago_p1 = ?, pago_p2 = ?, pago_p3 = ?, pago_p4 = ?
                  WHERE id = ?";

    $precio_cancha = intval(round(floatval($data['precio'] ?? 0)));
    $stmt = $conn->prepare($sqlUpdate);
    $stmt->bind_param("iiiiisssssssdissiiiii", 
        $cancha_id, 
        $jugador_id, $jugador2_id, $jugador3_id, $jugador4_id,
        $nombre_externo, $nombre_externo2, $nombre_externo3, $nombre_externo4,
        $fecha, $hora_inicio, $hora_fin, $precio_cancha, $pagado, $estado,
        $metodo_pago, $pago_p1, $pago_p2, $pago_p3, $pago_p4, $id
    );

if ($stmt->execute()) {
    $integrationError = null;

    // --- SINCRONIZACIÓN BIDIRECCIONAL CON LIGA ---
    $resLigaPart = $conn->query("SELECT liga_partido_id FROM reservas_cancha WHERE id = $id");
    if ($resLigaPart && $rowLP = $resLigaPart->fetch_assoc()) {
        $lpId = intval($rowLP['liga_partido_id'] ?? 0);
        if ($lpId > 0) {
            $hIniClean = str_pad($hora_inicio, 5, "0", STR_PAD_LEFT);
            $fechaHoraNueva = "$fecha " . (strlen($hIniClean) === 5 ? "$hIniClean:00" : $hIniClean);
            if ($estado === 'Cancelada') {
                $conn->query("UPDATE liga_partidos SET cancha_id = NULL, fecha_hora = NULL WHERE id = $lpId");
            } else {
                $conn->query("UPDATE liga_partidos SET cancha_id = $cancha_id, fecha_hora = '$fechaHoraNueva' WHERE id = $lpId");
            }
        }
    }

    // --- INTEGRACIÓN CON INVENTARIO Y VENTAS ---
    $checkVenta = $conn->query("SELECT venta_generada FROM reservas_cancha WHERE id = $id")->fetch_assoc();
    $ventaYaGenerada = $checkVenta ? intval($checkVenta['venta_generada']) : 0;
    $metodo_pago_v = ($metodo_pago == 'proporcional') ? 'Varios' : (($metodo_pago == 'total' || empty($metodo_pago)) ? 'Reserva' : ucfirst(strtolower($metodo_pago)));
    $vendedor_id = !empty($data['vendedor_id']) ? intval($data['vendedor_id']) : (!empty($data['usuario_vendedor_id']) ? intval($data['usuario_vendedor_id']) : 0);
    $cliente_id = intval($jugador_id ?? 0);

    // Si la reserva fue cancelada o el estado pagado cambió a 0 (DEBE/Pendiente) y antes ya tenía venta generada, eliminar la venta y devolver stock
    if ($estado === 'Cancelada') {
        try {
            $resVenta = $conn->query("SELECT id FROM inventario_ventas WHERE reserva_id = $id");
            if ($resVenta) {
                while ($rowV = $resVenta->fetch_assoc()) {
                    $vIdDel = intval($rowV['id']);
                    // Revertir stock de productos físicos consumidos
                    $stmtItems = $conn->query("SELECT vd.producto_id, vd.cantidad, v.club_id, p.tipo 
                                               FROM inventario_venta_detalles vd 
                                               JOIN inventario_ventas v ON vd.venta_id = v.id 
                                               LEFT JOIN inventario_productos p ON vd.producto_id = p.id 
                                               WHERE vd.venta_id = $vIdDel");
                    if ($stmtItems) {
                        while ($item = $stmtItems->fetch_assoc()) {
                            $p_id = intval($item['producto_id']);
                            $qty = floatval($item['cantidad']);
                            $club_id = intval($item['club_id']);
                            $tipo = isset($item['tipo']) ? strtolower(trim($item['tipo'])) : 'producto';
                            
                            if ($p_id > 0 && $tipo !== 'servicio') {
                                $conn->query("UPDATE inventario_productos SET stock_actual = stock_actual + $qty WHERE id = $p_id");
                                $stmtMov = $conn->prepare("INSERT INTO inventario_movimientos (club_id, producto_id, tipo, cantidad, motivo, referencia_id) 
                                                         VALUES (?, ?, 'entrada', ?, 'Cancelación Reserva', ?)");
                                if ($stmtMov) {
                                    $stmtMov->bind_param("iidi", $club_id, $p_id, $qty, $vIdDel);
                                    $stmtMov->execute();
                                }
                            }
                        }
                    }
                    $conn->query("DELETE FROM inventario_venta_detalles WHERE venta_id = $vIdDel");
                    $conn->query("DELETE FROM inventario_ventas WHERE id = $vIdDel");
                }
            }
            $conn->query("UPDATE reservas_consumos SET pagado = 0 WHERE reserva_id = $id");
            $conn->query("UPDATE reservas_cancha SET venta_generada = 0, pagado = 0 WHERE id = $id");
            $ventaYaGenerada = 0;
            $pagado = 0;
        } catch (Exception $eDel) {
            error_log("Error eliminando venta de reserva cancelada #$id: " . $eDel->getMessage());
        }
    } else if (intval($pagado) === 0 && $ventaYaGenerada == 1) {
        try {
            $resVenta = $conn->query("SELECT id FROM inventario_ventas WHERE reserva_id = $id");
            if ($resVenta) {
                while ($rowV = $resVenta->fetch_assoc()) {
                    $vIdDel = intval($rowV['id']);
                    // Revertir stock de productos físicos consumidos
                    $stmtItems = $conn->query("SELECT vd.producto_id, vd.cantidad, v.club_id, p.tipo 
                                               FROM inventario_venta_detalles vd 
                                               JOIN inventario_ventas v ON vd.venta_id = v.id 
                                               LEFT JOIN inventario_productos p ON vd.producto_id = p.id 
                                               WHERE vd.venta_id = $vIdDel");
                    if ($stmtItems) {
                        while ($item = $stmtItems->fetch_assoc()) {
                            $p_id = intval($item['producto_id']);
                            $qty = floatval($item['cantidad']);
                            $club_id = intval($item['club_id']);
                            $tipo = isset($item['tipo']) ? strtolower(trim($item['tipo'])) : 'producto';
                            
                            if ($p_id > 0 && $tipo !== 'servicio') {
                                $conn->query("UPDATE inventario_productos SET stock_actual = stock_actual + $qty WHERE id = $p_id");
                                $stmtMov = $conn->prepare("INSERT INTO inventario_movimientos (club_id, producto_id, tipo, cantidad, motivo, referencia_id) 
                                                         VALUES (?, ?, 'entrada', ?, 'Reserva a Debe', ?)");
                                if ($stmtMov) {
                                    $stmtMov->bind_param("iidi", $club_id, $p_id, $qty, $vIdDel);
                                    $stmtMov->execute();
                                }
                            }
                        }
                    }
                    $conn->query("DELETE FROM inventario_venta_detalles WHERE venta_id = $vIdDel");
                    $conn->query("DELETE FROM inventario_ventas WHERE id = $vIdDel");
                }
            }
            $conn->query("UPDATE reservas_cancha SET venta_generada = 0 WHERE id = $id");
            $ventaYaGenerada = 0;
        } catch (Exception $eDel) {
            error_log("Error eliminando venta de reserva no pagada #$id: " . $eDel->getMessage());
        }
    }

    // Caso A: Si la reserva está PAGADA y YA tiene venta generada o registrada en inventario_ventas
    $hInicioClean = !empty($hora_inicio) ? (strlen($hora_inicio) === 5 ? $hora_inicio . ':00' : $hora_inicio) : '12:00:00';
    $fechaVenta = !empty($fecha) ? ($fecha . ' ' . $hInicioClean) : date('Y-m-d H:i:s');
    
    $vIdExistente = 0;
    $resVenta = $conn->query("SELECT id FROM inventario_ventas WHERE reserva_id = $id ORDER BY id DESC LIMIT 1");
    if ($resVenta && $rowVenta = $resVenta->fetch_assoc()) {
        $vIdExistente = intval($rowVenta['id']);
    } else if ($estado !== 'Cancelada' && intval($pagado) === 1 && !empty($fecha)) {
        // Si no está vinculada por reserva_id, buscar si existe una venta de cancha sin reserva_id en la misma fecha y club
        $clubRow = $conn->query("SELECT club_id FROM canchas WHERE id = $cancha_id")->fetch_assoc();
        $club_id = $clubRow ? intval($clubRow['club_id']) : 0;
        if ($club_id > 0) {
            $resUnlinked = $conn->query("SELECT v.id FROM inventario_ventas v 
                                          INNER JOIN inventario_venta_detalles vd ON vd.venta_id = v.id 
                                          WHERE v.club_id = $club_id AND DATE(v.fecha) = '$fecha' 
                                          AND (v.reserva_id IS NULL OR v.reserva_id = 0) 
                                          AND vd.producto_id = 0 
                                          ORDER BY ABS(TIME_TO_SEC(TIME(v.fecha)) - TIME_TO_SEC('$hInicioClean')) ASC LIMIT 1");
            if ($resUnlinked && $rowU = $resUnlinked->fetch_assoc()) {
                $vIdExistente = intval($rowU['id']);
                // Vincular esta venta existente a la reserva
                $conn->query("UPDATE inventario_ventas SET reserva_id = $id WHERE id = $vIdExistente");
            }
        }
    }
    
    $hasExistingVenta = ($vIdExistente > 0);
    
    if ($estado !== 'Cancelada' && intval($pagado) === 1 && $hasExistingVenta) {
        try {
            // Recalcular total de consumos cobrados
            $resSumCons = $conn->query("SELECT COALESCE(SUM(subtotal), 0) as sum_c FROM reservas_consumos WHERE reserva_id = $id AND pagado = 1");
            $sumCons = 0;
            if ($resSumCons && $rowSumC = $resSumCons->fetch_assoc()) {
                $sumCons = floatval($rowSumC['sum_c']);
            }
            $nuevoTotalVenta = $precio_cancha + $sumCons;
            
            // Actualizar cabecera de la venta con la fecha y hora de la reserva
            $stmtUpdV = $conn->prepare("UPDATE inventario_ventas SET total = ?, metodo_pago = ?, fecha = ? WHERE id = ?");
            if ($stmtUpdV) {
                $stmtUpdV->bind_param("dssi", $nuevoTotalVenta, $metodo_pago_v, $fechaVenta, $vIdExistente);
                $stmtUpdV->execute();
            }
            
            // Actualizar el detalle de la cancha (producto_id = 0)
            $stmtUpdDetC = $conn->prepare("UPDATE inventario_venta_detalles SET precio_unitario = ? WHERE venta_id = ? AND producto_id = 0");
            if ($stmtUpdDetC) {
                $stmtUpdDetC->bind_param("di", $precio_cancha, $vIdExistente);
                $stmtUpdDetC->execute();
            }

            // Sincronizar ítems consumidos (RedBull, accesorios, etc.) a los detalles de la venta
            // Primero, limpiar detalles de productos existentes para esta venta y volver a insertarlos todos para evitar sobreescrituras en consumos compartidos.
            $conn->query("DELETE FROM inventario_venta_detalles WHERE venta_id = $vIdExistente AND producto_id > 0");

            $resConsSync = $conn->query("SELECT * FROM reservas_consumos WHERE reserva_id = $id");
            if ($resConsSync) {
                while ($cItem = $resConsSync->fetch_assoc()) {
                    $p_id = intval($cItem['producto_id']);
                    $qty = floatval($cItem['cantidad']);
                    $pu = floatval($cItem['precio_unitario']);
                    if ($p_id > 0) {
                        $stmtInsDet = $conn->prepare("INSERT INTO inventario_venta_detalles (venta_id, producto_id, cantidad, precio_unitario) VALUES (?, ?, ?, ?)");
                        if ($stmtInsDet) {
                            $stmtInsDet->bind_param("iidd", $vIdExistente, $p_id, $qty, $pu);
                            $stmtInsDet->execute();
                        }
                    }
                }
            }
            $conn->query("UPDATE reservas_consumos SET pagado = 1 WHERE reserva_id = $id");

            // Eliminar ventas duplicadas residuales para esta misma reserva y ventas de cancha huérfanas en la misma fecha
            $resExtraV = $conn->query("SELECT id FROM inventario_ventas WHERE reserva_id = $id AND id != $vIdExistente");
            if ($resExtraV) {
                $extraVIds = [];
                while ($rEx = $resExtraV->fetch_assoc()) {
                    $extraVIds[] = intval($rEx['id']);
                }
                if (!empty($extraVIds)) {
                    $strExIds = implode(',', $extraVIds);
                    $conn->query("DELETE FROM inventario_venta_detalles WHERE venta_id IN ($strExIds)");
                    $conn->query("DELETE FROM inventario_ventas WHERE id IN ($strExIds)");
                }
            }
            $conn->query("UPDATE reservas_cancha SET venta_generada = 1 WHERE id = $id");
        } catch (Exception $eEdit) {
            error_log("Error actualizando venta de reserva #$id: " . $eEdit->getMessage());
        }
    }

    // Caso B: Si la reserva está PAGADA (o tiene consumos) y AÚN NO ha generado su venta de cancha
    if ($estado !== 'Cancelada' && (intval($pagado) === 1 || !empty($items_consumo))) {
        try {
            $consumos_res = $conn->query("SELECT * FROM reservas_consumos WHERE reserva_id = $id AND pagado = 0");
            $total_consumos = 0;
            $items_consumo = [];
            if ($consumos_res) {
                while($c = $consumos_res->fetch_assoc()) {
                    $total_consumos += floatval($c['subtotal']);
                    $items_consumo[] = $c;
                }
            }

            $incluirCancha = (!$hasExistingVenta && intval($pagado) === 1 && $precio_cancha > 0);

            if ($incluirCancha || !empty($items_consumo)) {
                $clubRow = $conn->query("SELECT club_id FROM canchas WHERE id = $cancha_id")->fetch_assoc();
                $club_id = $clubRow ? intval($clubRow['club_id']) : 0;

                if ($club_id > 0) {
                    $conn->begin_transaction();

                    $montoCancha = $incluirCancha ? $precio_cancha : 0;
                    $total_venta = $montoCancha + $total_consumos;

                    $hInicioClean = !empty($hora_inicio) ? (strlen($hora_inicio) === 5 ? $hora_inicio . ':00' : $hora_inicio) : '12:00:00';
                    $fechaVenta = !empty($fecha) ? ($fecha . ' ' . $hInicioClean) : date('Y-m-d H:i:s');
                    $stmtVenta = $conn->prepare("INSERT INTO inventario_ventas (club_id, usuario_id, jugador_id, total, metodo_pago, reserva_id, fecha) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    if ($stmtVenta) {
                        $stmtVenta->bind_param("iiidsis", $club_id, $vendedor_id, $cliente_id, $total_venta, $metodo_pago_v, $id, $fechaVenta);
                        if ($stmtVenta->execute()) {
                            $venta_id = $conn->insert_id;

                            // Registrar la línea de la cancha incluso si el precio es 0
                            if ($incluirCancha) {
                                $stmtDetCancha = $conn->prepare("INSERT INTO inventario_venta_detalles (venta_id, producto_id, cantidad, precio_unitario) VALUES (?, 0, 1, ?)");
                                if ($stmtDetCancha) {
                                    $stmtDetCancha->bind_param("id", $venta_id, $precio_cancha);
                                    $stmtDetCancha->execute();
                                }
                                $conn->query("UPDATE reservas_cancha SET venta_generada = 1 WHERE id = $id");
                            }

                            // Registrar detalles de los productos consumidos
                            foreach ($items_consumo as $item) {
                                $p_id = (int)$item['producto_id'];
                                $qty = floatval($item['cantidad']);
                                $pu = floatval($item['precio_unitario']);

                                $stmtDetItem = $conn->prepare("INSERT INTO inventario_venta_detalles (venta_id, producto_id, cantidad, precio_unitario) VALUES (?, ?, ?, ?)");
                                if ($stmtDetItem) {
                                    $stmtDetItem->bind_param("iidd", $venta_id, $p_id, $qty, $pu);
                                    $stmtDetItem->execute();
                                }

                                $conn->query("UPDATE inventario_productos SET stock_actual = stock_actual - $qty WHERE id = $p_id");
                                
                                $motivo = "Consumo Reserva #$id (Jugador {$item['jugador_n']})";
                                $stmtMov = $conn->prepare("INSERT INTO inventario_movimientos (club_id, producto_id, tipo, cantidad, motivo, referencia_id) VALUES (?, ?, 'salida', ?, ?, ?)");
                                if ($stmtMov) {
                                    $stmtMov->bind_param("iidsi", $club_id, $p_id, $qty, $motivo, $venta_id);
                                    $stmtMov->execute();
                                }
                            }

                            $conn->query("UPDATE reservas_consumos SET pagado = 1 WHERE reserva_id = $id");
                            $conn->commit();
                        } else {
                            $conn->rollback();
                            $integrationError = "SQL Execute Exception: " . $stmtVenta->error;
                        }
                    } else {
                        $conn->rollback();
                        $integrationError = "SQL Prepare Exception: " . $conn->error;
                    }
                }
            }
        } catch (Exception $e) {
            @$conn->rollback();
            $integrationError = "Internal Exception: " . $e->getMessage();
        }
    }

    // Notificación por correo en segundo plano
    try {
        require_once "../system/mail_service.php";
        if (isset($data['estado']) && $data['estado'] === 'Cancelada') {
            notificarReservaCancha($conn, $id, 'cancelacion');
        } else {
            // Detectar nuevos jugadores agregados
            $newPlayerIds = [];
            if (!empty($jugador_id)) $newPlayerIds[] = intval($jugador_id);
            if (!empty($jugador2_id)) $newPlayerIds[] = intval($jugador2_id);
            if (!empty($jugador3_id)) $newPlayerIds[] = intval($jugador3_id);
            if (!empty($jugador4_id)) $newPlayerIds[] = intval($jugador4_id);

            $newlyAddedPlayerIds = array_diff($newPlayerIds, $oldPlayerIds);
            if (!empty($newlyAddedPlayerIds)) {
                notificarReservaCancha($conn, $id, 'actualizacion', $newlyAddedPlayerIds);
            }
        }
    } catch (Throwable $e) {
        error_log("Error enviando notificacion de reserva: " . $e->getMessage());
    }

    $conn->commit();

    @ob_clean();
    echo json_encode([
        "success" => true, 
        "warning" => $integrationError
    ]);
} else {
    @$conn->rollback();
    @ob_clean();
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Error al actualizar la reserva: " . $conn->error]);
}
} catch (Throwable $eGlobal) {
    if (isset($conn) && $conn) {
        @$conn->rollback();
    }
    @ob_clean();
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Excepción atrapada: " . $eGlobal->getMessage()]);
}
?>
