<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type, X-Requested-With, Accept, Origin");
header("Access-Control-Max-Age: 86400");
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

ob_start();
error_reporting(0);
ini_set('display_errors', 0);

require_once "../db.php";

$club_id = $_GET['club_id'] ?? null;
$fecha_inicio = $_GET['finicio'] ?? date('Y-m-d');
$fecha_fin = $_GET['ffin'] ?? date('Y-m-d');

if (!$club_id) {
    http_response_code(400);
    echo json_encode(["error" => "club_id es requerido"]);
    exit;
}

try {
    // 0. Verificar si la columna jugador_id existe en la tabla inventario_ventas sin bloquear metadatos
    $hasJugadorCol = false;
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM inventario_ventas LIKE 'jugador_id'");
        if ($chk && $chk->rowCount() > 0) {
            $hasJugadorCol = true;
        } else {
            $pdo->exec("ALTER TABLE inventario_ventas ADD COLUMN jugador_id INT NULL");
            $hasJugadorCol = true;
        }
    } catch (Exception $eCol) {
        $hasJugadorCol = true;
    }

    // 1. Limpiar cualquier venta huérfana de reservas que hayan pasado a estado DEBE (pagado = 0) o Canceladas
    try {
        // Encontrar IDs de ventas de reservas canceladas o no pagadas
        $stmtCancelVentas = $pdo->prepare("SELECT v.id as venta_id, r.id as reserva_id 
                                           FROM inventario_ventas v 
                                           JOIN reservas_cancha r ON v.reserva_id = r.id 
                                           WHERE (r.pagado = 0 OR r.estado = 'Cancelada') AND v.club_id = ?");
        $stmtCancelVentas->execute([$club_id]);
        $cancelVentas = $stmtCancelVentas->fetchAll(PDO::FETCH_ASSOC);

        foreach ($cancelVentas as $cV) {
            $vIdDel = (int)$cV['venta_id'];
            $rIdDel = (int)$cV['reserva_id'];

            // Revertir stock de productos físicos consumidos
            $stmtItems = $pdo->prepare("SELECT vd.producto_id, vd.cantidad, v.club_id, p.tipo 
                                        FROM inventario_venta_detalles vd 
                                        JOIN inventario_ventas v ON vd.venta_id = v.id 
                                        LEFT JOIN inventario_productos p ON vd.producto_id = p.id 
                                        WHERE vd.venta_id = ?");
            $stmtItems->execute([$vIdDel]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as $item) {
                $p_id = (int)$item['producto_id'];
                $qty = floatval($item['cantidad']);
                $c_id = (int)$item['club_id'];
                $tipo = isset($item['tipo']) ? strtolower(trim($item['tipo'])) : 'producto';
                if ($p_id > 0 && $tipo !== 'servicio') {
                    $pdo->exec("UPDATE inventario_productos SET stock_actual = stock_actual + $qty WHERE id = $p_id");
                    $stmtMov = $pdo->prepare("INSERT INTO inventario_movimientos (club_id, producto_id, tipo, cantidad, motivo, referencia_id) VALUES (?, ?, 'entrada', ?, 'Cancelación Reserva', ?)");
                    $stmtMov->execute([$c_id, $p_id, $qty, $vIdDel]);
                }
            }

            $pdo->exec("DELETE FROM inventario_venta_detalles WHERE venta_id = $vIdDel");
            $pdo->exec("DELETE FROM inventario_ventas WHERE id = $vIdDel");
            $pdo->exec("UPDATE reservas_cancha SET venta_generada = 0 WHERE id = $rIdDel");
            $pdo->exec("UPDATE reservas_consumos SET pagado = 0 WHERE reserva_id = $rIdDel");
        }

        // Limpiar ventas cuya reserva asociada ya no existe en reservas_cancha
        $stmtGhost = $pdo->prepare("SELECT v.id FROM inventario_ventas v 
                                    WHERE v.club_id = ? AND v.reserva_id IS NOT NULL AND v.reserva_id > 0 
                                    AND NOT EXISTS (SELECT 1 FROM reservas_cancha r WHERE r.id = v.reserva_id)");
        $stmtGhost->execute([$club_id]);
        $ghostIds = $stmtGhost->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($ghostIds)) {
            $inGhost = implode(',', array_map('intval', $ghostIds));
            $pdo->exec("DELETE FROM inventario_venta_detalles WHERE venta_id IN ($inGhost)");
            $pdo->exec("DELETE FROM inventario_ventas WHERE id IN ($inGhost)");
        }

        // Limpiar ventas con total 0 pertenecientes a reservas de cancha (como entrenamientos sin costo o bloqueos)
        $pdo->exec("DELETE vd FROM inventario_venta_detalles vd 
                    INNER JOIN inventario_ventas v ON vd.venta_id = v.id 
                    WHERE v.club_id = $club_id AND v.total = 0 AND v.reserva_id IS NOT NULL AND v.reserva_id > 0");

        $pdo->exec("DELETE v FROM inventario_ventas v 
                    WHERE v.club_id = $club_id AND v.total = 0 AND v.reserva_id IS NOT NULL AND v.reserva_id > 0");

        // Limpiar ventas de cancha genéricas y huérfanas sin reserva_id válida cuando en la misma fecha ya existe una reserva pagada para el club
        $stmtToDel = $pdo->prepare("SELECT v.id FROM inventario_ventas v 
                                    WHERE (v.reserva_id IS NULL OR v.reserva_id = 0 OR NOT EXISTS (SELECT 1 FROM reservas_cancha r WHERE r.id = v.reserva_id AND r.pagado = 1 AND r.estado != 'Cancelada')) 
                                    AND v.club_id = ?
                                    AND NOT EXISTS (SELECT 1 FROM inventario_venta_detalles vd2 WHERE vd2.venta_id = v.id AND vd2.producto_id > 0)
                                    AND EXISTS (SELECT 1 FROM inventario_ventas v2 JOIN reservas_cancha r2 ON v2.reserva_id = r2.id WHERE v2.club_id = v.club_id AND DATE(v2.fecha) = DATE(v.fecha) AND r2.pagado = 1 AND r2.estado != 'Cancelada')");
        $stmtToDel->execute([$club_id]);
        $idsToDel = $stmtToDel->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($idsToDel)) {
            $inClause = implode(',', array_map('intval', $idsToDel));
            $pdo->exec("DELETE FROM inventario_venta_detalles WHERE venta_id IN ($inClause)");
            $pdo->exec("DELETE FROM inventario_ventas WHERE id IN ($inClause)");
        }

        // Auto-vincular ventas de cancha huérfanas con reservas pagadas del mismo día que coincidan en hora o monto
        $stmtUnlinkedSales = $pdo->prepare("SELECT v.id, v.fecha, v.total 
                                             FROM inventario_ventas v 
                                             WHERE (v.reserva_id IS NULL OR v.reserva_id = 0) AND v.club_id = ?
                                             AND NOT EXISTS (SELECT 1 FROM inventario_venta_detalles vd2 WHERE vd2.venta_id = v.id AND vd2.producto_id > 0)");
        $stmtUnlinkedSales->execute([$club_id]);
        $unlinkedSales = $stmtUnlinkedSales->fetchAll(PDO::FETCH_ASSOC);
        foreach ($unlinkedSales as $uSale) {
            $vId = (int)$uSale['id'];
            $vDate = date('Y-m-d', strtotime($uSale['fecha']));
            $vTime = date('H:i:s', strtotime($uSale['fecha']));
            
            $stmtFindRes = $pdo->prepare("SELECT r.id FROM reservas_cancha r 
                                          JOIN canchas c ON r.cancha_id = c.id 
                                          WHERE c.club_id = ? AND r.fecha = ? AND r.pagado = 1 AND r.estado != 'Cancelada'
                                          AND NOT EXISTS (SELECT 1 FROM inventario_ventas v3 WHERE v3.reserva_id = r.id)
                                          ORDER BY ABS(TIME_TO_SEC(r.hora_inicio) - TIME_TO_SEC(?)) ASC LIMIT 1");
            $stmtFindRes->execute([$club_id, $vDate, $vTime]);
            if ($rowRes = $stmtFindRes->fetch(PDO::FETCH_ASSOC)) {
                $rIdFound = (int)$rowRes['id'];
                $pdo->exec("UPDATE inventario_ventas SET reserva_id = $rIdFound WHERE id = $vId");
                $pdo->exec("UPDATE reservas_cancha SET venta_generada = 1 WHERE id = $rIdFound");
            }
        }
    } catch (Throwable $eClean) {}

    // 2. Corregir ventas sin fecha asignada asignándoles la fecha actual
    $pdo->exec("UPDATE inventario_ventas SET fecha = NOW() WHERE fecha IS NULL OR fecha = '0000-00-00 00:00:00'");

    // 2. Auto-sincronizar reservas pagadas que aún no tengan registro de venta_generada (solo si el precio de cancha > 0)
    $sqlUnsynced = "SELECT r.*, c.club_id 
                    FROM reservas_cancha r 
                    JOIN canchas c ON r.cancha_id = c.id 
                    WHERE c.club_id = ? AND r.pagado = 1 AND r.precio > 0 AND r.estado != 'Cancelada' AND (r.venta_generada = 0 OR r.venta_generada IS NULL)";
    $stmtUnsynced = $pdo->prepare($sqlUnsynced);
    $stmtUnsynced->execute([$club_id]);
    $unsynced = $stmtUnsynced->fetchAll(PDO::FETCH_ASSOC);

    foreach ($unsynced as $rRes) {
        $resId = (int)$rRes['id'];

        // Evitar duplicar ventas si la reserva ya tiene una venta registrada en inventario_ventas
        $stmtChkExist = $pdo->prepare("SELECT id FROM inventario_ventas WHERE reserva_id = ?");
        $stmtChkExist->execute([$resId]);
        if ($stmtChkExist->fetch()) {
            $pdo->exec("UPDATE reservas_cancha SET venta_generada = 1 WHERE id = $resId");
            continue;
        }
        $cId = (int)$rRes['club_id'];
        $pUser = (int)($rRes['usuario_id'] ?? 0);
        $precio = floatval($rRes['precio'] ?? 0);
        $fechaVenta = !empty($rRes['fecha']) ? ($rRes['fecha'] . ' ' . ($rRes['hora_inicio'] ?? '12:00:00')) : date('Y-m-d H:i:s');
        $rawMeth = strtolower($rRes['metodo_pago'] ?? '');
        $metodoV = ($rawMeth === 'proporcional') ? 'Varios' : (($rawMeth === 'total' || empty($rawMeth)) ? 'Reserva' : ucfirst($rawMeth));

        // Obtener consumos no cobrados
        $stmtCons = $pdo->prepare("SELECT * FROM reservas_consumos WHERE reserva_id = ? AND pagado = 0");
        $stmtCons->execute([$resId]);
        $consumos = $stmtCons->fetchAll(PDO::FETCH_ASSOC);

        $totalConsumos = 0;
        foreach ($consumos as $co) {
            $totalConsumos += floatval($co['subtotal']);
        }

        $totalVenta = $precio + $totalConsumos;

        if ($hasJugadorCol) {
            $stmtInsVenta = $pdo->prepare("INSERT INTO inventario_ventas (club_id, usuario_id, jugador_id, total, metodo_pago, reserva_id, fecha) VALUES (?, 0, ?, ?, ?, ?, ?)");
            $stmtInsVenta->execute([$cId, $pUser, $totalVenta, $metodoV, $resId, $fechaVenta]);
        } else {
            $stmtInsVenta = $pdo->prepare("INSERT INTO inventario_ventas (club_id, usuario_id, total, metodo_pago, reserva_id, fecha) VALUES (?, 0, ?, ?, ?, ?)");
            $stmtInsVenta->execute([$cId, $totalVenta, $metodoV, $resId, $fechaVenta]);
        }
        
        $vId = $pdo->lastInsertId();

        // Detalle Cancha
        $stmtDetC = $pdo->prepare("INSERT INTO inventario_venta_detalles (venta_id, producto_id, cantidad, precio_unitario) VALUES (?, 0, 1, ?)");
        $stmtDetC->execute([$vId, $precio]);

        // Detalles Consumos
        foreach ($consumos as $co) {
            $pId = (int)$co['producto_id'];
            $qty = (int)$co['cantidad'];
            $pu = floatval($co['precio_unitario']);
            $stmtDetCo = $pdo->prepare("INSERT INTO inventario_venta_detalles (venta_id, producto_id, cantidad, precio_unitario) VALUES (?, ?, ?, ?)");
            $stmtDetCo->execute([$vId, $pId, $qty, $pu]);

            $pdo->exec("UPDATE inventario_productos SET stock_actual = stock_actual - $qty WHERE id = $pId");
        }

        $pdo->exec("UPDATE reservas_consumos SET pagado = 1 WHERE reserva_id = $resId");
        $pdo->exec("UPDATE reservas_cancha SET venta_generada = 1 WHERE id = $resId");
    }

    // Auto-sincronizar consumos de reservas en ventas existentes que aún no figuren en inventario_venta_detalles
    try {
        $stmtCheckCons = $pdo->prepare("SELECT rc.*, v.id as v_id 
                                        FROM reservas_consumos rc 
                                        JOIN inventario_ventas v ON rc.reserva_id = v.reserva_id 
                                        WHERE v.club_id = ? AND rc.producto_id > 0");
        $stmtCheckCons->execute([$club_id]);
        $rcList = $stmtCheckCons->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rcList as $rcItem) {
            $vId = (int)$rcItem['v_id'];
            $pId = (int)$rcItem['producto_id'];
            $qty = floatval($rcItem['cantidad']);
            $pu = floatval($rcItem['precio_unitario']);
            
            $stmtExists = $pdo->prepare("SELECT id FROM inventario_venta_detalles WHERE venta_id = ? AND producto_id = ?");
            $stmtExists->execute([$vId, $pId]);
            if (!$stmtExists->fetch()) {
                $stmtIns = $pdo->prepare("INSERT INTO inventario_venta_detalles (venta_id, producto_id, cantidad, precio_unitario) VALUES (?, ?, ?, ?)");
                $stmtIns->execute([$vId, $pId, $qty, $pu]);
            }
            $pdo->exec("UPDATE reservas_consumos SET pagado = 1 WHERE id = {$rcItem['id']}");
        }
    } catch (Throwable $eSyncCons) {}

    // 3. Obtener cabeceras de ventas con detalles de vendedor, cliente y reserva de cancha
    if ($hasJugadorCol) {
        $sql = "SELECT v.*, u.nombre as vendedor, uj.nombre as cliente, 
                       c.nombre as cancha_nombre, r.hora_inicio, r.hora_fin, r.fecha as reserva_fecha,
                       r.estado as reserva_estado, r.categoria as reserva_categoria, r.nombre_externo as reserva_nombre_externo 
                FROM inventario_ventas v
                LEFT JOIN usuarios u ON v.usuario_id = u.id
                LEFT JOIN usuarios uj ON v.jugador_id = uj.id
                LEFT JOIN reservas_cancha r ON v.reserva_id = r.id
                LEFT JOIN canchas c ON r.cancha_id = c.id
                WHERE v.club_id = ? AND DATE(v.fecha) BETWEEN ? AND ?
                  AND (v.reserva_id IS NULL OR v.reserva_id = 0 OR (r.id IS NOT NULL AND r.estado != 'Cancelada' AND r.pagado = 1))
                ORDER BY v.fecha DESC";
    } else {
        $sql = "SELECT v.*, u.nombre as vendedor, NULL as cliente, 
                       c.nombre as cancha_nombre, r.hora_inicio, r.hora_fin, r.fecha as reserva_fecha,
                       r.estado as reserva_estado, r.categoria as reserva_categoria, r.nombre_externo as reserva_nombre_externo 
                FROM inventario_ventas v
                LEFT JOIN usuarios u ON v.usuario_id = u.id
                LEFT JOIN reservas_cancha r ON v.reserva_id = r.id
                LEFT JOIN canchas c ON r.cancha_id = c.id
                WHERE v.club_id = ? AND DATE(v.fecha) BETWEEN ? AND ?
                  AND (v.reserva_id IS NULL OR v.reserva_id = 0 OR (r.id IS NOT NULL AND r.estado != 'Cancelada' AND r.pagado = 1))
                ORDER BY v.fecha DESC";
    }
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$club_id, $fecha_inicio, $fecha_fin]);
    $ventas = $stmt->fetchAll();
    
    // Batch fetching de ítems para eliminar el problema N+1
    $vIds = array_map(function($v) { return intval($v['id']); }, $ventas);
    $itemsByVenta = [];
    if (!empty($vIds)) {
        $vIdsStr = implode(',', $vIds);
        $stmtAllItems = $pdo->query("SELECT vd.*, 
                                            COALESCE(p.nombre, 'Alquiler de Cancha') as producto_nombre, 
                                            COALESCE(p.tipo, 'servicio') as tipo, 
                                            p.categoria,
                                            COALESCE(p.precio_costo, 0.00) as precio_costo
                                     FROM inventario_venta_detalles vd
                                     LEFT JOIN inventario_productos p ON vd.producto_id = p.id
                                     WHERE vd.venta_id IN ($vIdsStr)");
        if ($stmtAllItems) {
            while ($rowItem = $stmtAllItems->fetch(PDO::FETCH_ASSOC)) {
                $itemsByVenta[$rowItem['venta_id']][] = $rowItem;
            }
        }
    }

    // Procesar cada venta
    foreach ($ventas as &$venta) {
        $items = $itemsByVenta[$venta['id']] ?? [];

        $canchaNombre = $venta['cancha_nombre'] ?? '';
        $hInicio = !empty($venta['hora_inicio']) ? substr($venta['hora_inicio'], 0, 5) : '';
        if (empty($hInicio) && !empty($venta['fecha'])) {
            $hInicio = date('H:i', strtotime($venta['fecha']));
        }

        // Identificar tipo de servicio (Alquiler de Cancha, Torneo Americano, Entrenamiento)
        $resEstado = $venta['reserva_estado'] ?? '';
        $resCat = $venta['reserva_categoria'] ?? '';
        $resExt = $venta['reserva_nombre_externo'] ?? '';

        $isAmericano = (strcasecmp($resEstado, 'Americano') === 0 || stripos($resCat, 'Americano') !== false || stripos($resExt, 'Americano') !== false);
        $isEntrenamiento = (strcasecmp($resEstado, 'Entrenamiento') === 0 || stripos($resCat, 'Entrenamiento') !== false);

        $nombreServicio = "Alquiler de Cancha";
        if ($isAmericano) {
            $nombreServicio = "Torneo Americano";
        } else if ($isEntrenamiento) {
            $nombreServicio = "Entrenamiento";
        }

        // Si no tenemos cancha_nombre pero la venta incluye alquiler/servicio (producto_id = 0)
        $hasCanchaItem = false;
        foreach ($items as $it) {
            if ((int)$it['producto_id'] === 0) {
                $hasCanchaItem = true;
                break;
            }
        }

        if ((empty($items) || $hasCanchaItem) && empty($canchaNombre)) {
            $resIdLook = (int)($venta['reserva_id'] ?? 0);
            if ($resIdLook > 0) {
                $stmtR = $pdo->prepare("SELECT c.nombre as cancha_nombre, r.hora_inicio, r.estado as r_est, r.categoria as r_cat, r.nombre_externo as r_ext FROM reservas_cancha r LEFT JOIN canchas c ON r.cancha_id = c.id WHERE r.id = ?");
                $stmtR->execute([$resIdLook]);
                if ($rowR = $stmtR->fetch(PDO::FETCH_ASSOC)) {
                    $canchaNombre = $rowR['cancha_nombre'] ?? '';
                    $hInicio = !empty($rowR['hora_inicio']) ? substr($rowR['hora_inicio'], 0, 5) : '';
                    $venta['cancha_nombre'] = $canchaNombre;
                    $venta['hora_inicio'] = $rowR['hora_inicio'];

                    $rEst = $rowR['r_est'] ?? '';
                    $rCat = $rowR['r_cat'] ?? '';
                    $rExt = $rowR['r_ext'] ?? '';
                    if (strcasecmp($rEst, 'Americano') === 0 || stripos($rCat, 'Americano') !== false || stripos($rExt, 'Americano') !== false) {
                        $nombreServicio = "Torneo Americano";
                    } else if (strcasecmp($rEst, 'Entrenamiento') === 0 || stripos($rCat, 'Entrenamiento') !== false) {
                        $nombreServicio = "Entrenamiento";
                    }
                }
            }
            if (empty($canchaNombre)) {
                try {
                    $fechaSolo = date('Y-m-d', strtotime($venta['fecha']));
                    $vTime = date('H:i:s', strtotime($venta['fecha']));
                    $vJug = (int)($venta['jugador_id'] ?? $venta['usuario_id'] ?? 0);

                    if ($vJug > 0) {
                        $stmtR = $pdo->prepare("SELECT r.id as res_id, c.nombre as cancha_nombre, r.hora_inicio, r.estado as r_est, r.categoria as r_cat, r.nombre_externo as r_ext 
                                                FROM reservas_cancha r 
                                                INNER JOIN canchas c ON r.cancha_id = c.id 
                                                WHERE c.club_id = ? AND r.fecha = ? AND (r.usuario_id = ? OR r.usuario_id IS NULL) AND r.estado != 'Cancelada'
                                                ORDER BY ABS(TIME_TO_SEC(r.hora_inicio) - TIME_TO_SEC(?)) ASC LIMIT 1");
                        $stmtR->execute([$club_id, $fechaSolo, $vJug, $vTime]);
                        if ($rowR = $stmtR->fetch(PDO::FETCH_ASSOC)) {
                            $canchaNombre = $rowR['cancha_nombre'] ?? '';
                            $hInicio = !empty($rowR['hora_inicio']) ? substr($rowR['hora_inicio'], 0, 5) : '';
                            $venta['cancha_nombre'] = $canchaNombre;
                            $venta['hora_inicio'] = $rowR['hora_inicio'];
                            $venta['reserva_id'] = $rowR['res_id'];
                            $pdo->exec("UPDATE inventario_ventas SET reserva_id = {$rowR['res_id']} WHERE id = {$venta['id']}");
                        }
                    }

                    if (empty($canchaNombre)) {
                        $stmtR = $pdo->prepare("SELECT r.id as res_id, c.nombre as cancha_nombre, r.hora_inicio, r.estado as r_est, r.categoria as r_cat, r.nombre_externo as r_ext 
                                                FROM reservas_cancha r 
                                                INNER JOIN canchas c ON r.cancha_id = c.id 
                                                WHERE c.club_id = ? AND r.fecha = ? AND r.estado != 'Cancelada'
                                                ORDER BY ABS(TIME_TO_SEC(r.hora_inicio) - TIME_TO_SEC(?)) ASC LIMIT 1");
                        $stmtR->execute([$club_id, $fechaSolo, $vTime]);
                        if ($rowR = $stmtR->fetch(PDO::FETCH_ASSOC)) {
                            $canchaNombre = $rowR['cancha_nombre'] ?? '';
                            $hInicio = !empty($rowR['hora_inicio']) ? substr($rowR['hora_inicio'], 0, 5) : '';
                            $venta['cancha_nombre'] = $canchaNombre;
                            $venta['hora_inicio'] = $rowR['hora_inicio'];
                            $venta['reserva_id'] = $rowR['res_id'];
                            $pdo->exec("UPDATE inventario_ventas SET reserva_id = {$rowR['res_id']} WHERE id = {$venta['id']}");
                        }
                    }
                } catch (Throwable $eMatch) {}
            }
        }

        // Si la venta no tiene ítems en detalles, crear un ítem sintético para que no aparezca en blanco
        if (empty($hInicio) && !empty($venta['fecha'])) {
            $hInicio = date('H:i', strtotime($venta['fecha']));
        }

        if (empty($items)) {
            $hTxt = $hInicio ? " ({$hInicio} hs)" : '';
            $lbl = $nombreServicio . (!empty($canchaNombre) ? " - " . $canchaNombre : "") . $hTxt;
            $items = [[
                'id' => 0,
                'venta_id' => (int)$venta['id'],
                'producto_id' => 0,
                'cantidad' => 1,
                'precio_unitario' => floatval($venta['total']),
                'precio_costo' => 0.00,
                'producto_nombre' => $lbl,
                'tipo' => 'servicio',
                'categoria' => 'Servicios'
            ]];
        } else {
            $grouped = [];
            $rowCountByPid = [];
            foreach ($items as $item) {
                $pid = (int)($item['producto_id'] ?? 0);
                $pName = trim($item['producto_nombre'] ?? '');
                $pNameLower = strtolower($pName);

                $isCanchaService = ($pid === 0);

                if ($isCanchaService) {
                    $horaTxt = $hInicio ? " ({$hInicio} hs)" : '';
                    $lblDetailed = $nombreServicio . (!empty($canchaNombre) ? " - " . $canchaNombre : "") . $horaTxt;

                    $canchaKey = 'cancha_0';
                    $c = floatval($item['cantidad'] ?? 1);
                    $p = floatval($item['precio_unitario'] ?? 0);

                    if (!isset($grouped[$canchaKey])) {
                        $item['producto_id'] = 0;
                        $item['producto_nombre'] = $lblDetailed;
                        $item['tipo'] = 'servicio';
                        $item['cantidad'] = ($c > 0) ? $c : 1;
                        $item['precio_unitario'] = $p;
                        $item['subtotal'] = $item['cantidad'] * $p;
                        $grouped[$canchaKey] = $item;
                    } else {
                        // Si ya existía el ítem de cancha pero el actual contiene el nombre detallado con canchaNombre, actualizar el nombre
                        if (!empty($canchaNombre) && strpos($grouped[$canchaKey]['producto_nombre'], $canchaNombre) === false) {
                            $grouped[$canchaKey]['producto_nombre'] = $lblDetailed;
                        }
                        if ($grouped[$canchaKey]['precio_unitario'] == 0 && $p > 0) {
                            $grouped[$canchaKey]['precio_unitario'] = $p;
                            $grouped[$canchaKey]['subtotal'] = $grouped[$canchaKey]['cantidad'] * $p;
                        }
                    }
                } else {
                    if (!isset($rowCountByPid[$pid])) {
                        $rowCountByPid[$pid] = 0;
                    }
                    $rowCountByPid[$pid]++;

                    $c = floatval($item['cantidad']);
                    $p = floatval($item['precio_unitario']);
                    $st = $c * $p;

                    if (!isset($grouped[$pid])) {
                        $item['cantidad'] = $c;
                        $item['precio_unitario'] = $p;
                        $item['subtotal'] = $st;
                        $grouped[$pid] = $item;
                    } else {
                        $grouped[$pid]['cantidad'] += $c;
                        $grouped[$pid]['subtotal'] += $st;
                    }
                }
            }

            $items = array_values($grouped);
            foreach ($items as &$it) {
                $pid = (int)$it['producto_id'];
                $cantNum = floatval($it['cantidad']);
                $subtNum = floatval($it['subtotal'] ?? 0);
                
                $it['cantidad'] = ($cantNum == floor($cantNum)) ? (int)$cantNum : $cantNum;
                if ($it['cantidad'] > 0) {
                    $it['precio_unitario'] = round($subtNum / $it['cantidad'], 2);
                }
            }
            unset($it);
        }

        $venta['items'] = $items;
    }
    unset($venta);
    
    echo json_encode(["success" => true, "version" => "1.0.6", "ventas" => $ventas]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Error al obtener ventas: " . $e->getMessage()]);
}
