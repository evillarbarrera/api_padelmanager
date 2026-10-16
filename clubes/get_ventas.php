<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

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
    // 0. Asegurar que la columna jugador_id exista en la tabla inventario_ventas
    try {
        $pdo->exec("ALTER TABLE inventario_ventas ADD COLUMN jugador_id INT NULL");
    } catch (PDOException $eCol) {
        // Ignorar si ya existe la columna
    }

    $hasJugadorCol = false;
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM inventario_ventas LIKE 'jugador_id'");
        if ($chk && $chk->rowCount() > 0) {
            $hasJugadorCol = true;
        }
    } catch (Exception $eCol) {}

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
    } catch (Exception $eClean) {}

    // 2. Corregir ventas sin fecha asignada asignándoles la fecha actual
    $pdo->exec("UPDATE inventario_ventas SET fecha = NOW() WHERE fecha IS NULL OR fecha = '0000-00-00 00:00:00'");

    // 2. Auto-sincronizar reservas pagadas que aún no tengan registro de venta_generada
    $sqlUnsynced = "SELECT r.*, c.club_id 
                    FROM reservas_cancha r 
                    JOIN canchas c ON r.cancha_id = c.id 
                    WHERE c.club_id = ? AND r.pagado = 1 AND r.estado != 'Cancelada' AND (r.venta_generada = 0 OR r.venta_generada IS NULL)";
    $stmtUnsynced = $pdo->prepare($sqlUnsynced);
    $stmtUnsynced->execute([$club_id]);
    $unsynced = $stmtUnsynced->fetchAll(PDO::FETCH_ASSOC);

    foreach ($unsynced as $rRes) {
        $resId = (int)$rRes['id'];
        $cId = (int)$rRes['club_id'];
        $pUser = (int)($rRes['usuario_id'] ?? 0);
        $precio = floatval($rRes['precio'] ?? 0);
        $fechaVenta = !empty($rRes['fecha']) ? ($rRes['fecha'] . ' ' . ($rRes['hora_inicio'] ?? '12:00:00')) : date('Y-m-d H:i:s');
        $metodoV = (strtolower($rRes['metodo_pago'] ?? '') === 'proporcional') ? 'Varios' : 'Reserva';

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

    // 3. Obtener cabeceras de ventas con detalles de vendedor, cliente y reserva de cancha
    if ($hasJugadorCol) {
        $sql = "SELECT v.*, u.nombre as vendedor, uj.nombre as cliente, 
                       c.nombre as cancha_nombre, r.hora_inicio, r.hora_fin, r.fecha as reserva_fecha 
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
                       c.nombre as cancha_nombre, r.hora_inicio, r.hora_fin, r.fecha as reserva_fecha 
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
                                            p.categoria 
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

        // Si no tenemos cancha_nombre pero la venta incluye alquiler de cancha (producto_id = 0)
        $hasCanchaItem = false;
        foreach ($items as $it) {
            if ((int)$it['producto_id'] === 0) {
                $hasCanchaItem = true;
                break;
            }
        }

        if ($hasCanchaItem && empty($canchaNombre)) {
            $resIdLook = (int)($venta['reserva_id'] ?? 0);
            if ($resIdLook > 0) {
                $stmtR = $pdo->prepare("SELECT c.nombre as cancha_nombre, r.hora_inicio FROM reservas_cancha r JOIN canchas c ON r.cancha_id = c.id WHERE r.id = ?");
                $stmtR->execute([$resIdLook]);
                if ($rowR = $stmtR->fetch(PDO::FETCH_ASSOC)) {
                    $canchaNombre = $rowR['cancha_nombre'];
                    $hInicio = !empty($rowR['hora_inicio']) ? substr($rowR['hora_inicio'], 0, 5) : '';
                    $venta['cancha_nombre'] = $canchaNombre;
                    $venta['hora_inicio'] = $rowR['hora_inicio'];
                }
            } else {
                // Fallback por jugador y fecha
                $jugId = (int)($venta['jugador_id'] ?? $venta['usuario_id'] ?? 0);
                $fechaSolo = date('Y-m-d', strtotime($venta['fecha']));
                if ($jugId > 0) {
                    $stmtR = $pdo->prepare("SELECT r.id as res_id, c.nombre as cancha_nombre, r.hora_inicio 
                                            FROM reservas_cancha r 
                                            JOIN canchas c ON r.cancha_id = c.id 
                                            WHERE c.club_id = ? AND (r.usuario_id = ? OR r.jugador2_id = ? OR r.jugador3_id = ? OR r.jugador4_id = ?) AND r.fecha = ? 
                                            ORDER BY r.id DESC LIMIT 1");
                    $stmtR->execute([$club_id, $jugId, $jugId, $jugId, $jugId, $fechaSolo]);
                    if ($rowR = $stmtR->fetch(PDO::FETCH_ASSOC)) {
                        $canchaNombre = $rowR['cancha_nombre'];
                        $hInicio = !empty($rowR['hora_inicio']) ? substr($rowR['hora_inicio'], 0, 5) : '';
                        $venta['cancha_nombre'] = $canchaNombre;
                        $venta['hora_inicio'] = $rowR['hora_inicio'];
                        $venta['reserva_id'] = $rowR['res_id'];
                        $pdo->exec("UPDATE inventario_ventas SET reserva_id = {$rowR['res_id']} WHERE id = {$venta['id']}");
                    }
                }
            }
        }

        foreach ($items as &$item) {
            if ((int)$item['producto_id'] === 0) {
                $horaTxt = $hInicio ? " ({$hInicio} hs)" : '';
                if (!empty($canchaNombre)) {
                    $item['producto_nombre'] = "Alquiler de Cancha - " . $canchaNombre . $horaTxt;
                } else {
                    $item['producto_nombre'] = "Alquiler de Cancha" . $horaTxt;
                }
            }
        }
        $venta['items'] = $items;
    }
    
    echo json_encode(["success" => true, "ventas" => $ventas]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Error al obtener ventas: " . $e->getMessage()]);
}
