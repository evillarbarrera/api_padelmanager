<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);

if (!$data || !isset($data['venta_id']) || !isset($data['items']) || empty($data['items'])) {
    ob_clean();
    http_response_code(400);
    echo json_encode(["error" => "Faltan datos de la venta para actualizar"]);
    exit;
}

$venta_id = intval($data['venta_id']);

// 0. Asegurar columna jugador_id (ANTES de iniciar transacción para evitar commit implícito en MySQL)
try {
    $pdo->exec("ALTER TABLE inventario_ventas ADD COLUMN jugador_id INT NULL");
} catch (PDOException $eCol) {}

try {
    $pdo->beginTransaction();

    // Verificar que la venta exista
    $stmtExist = $pdo->prepare("SELECT id, reserva_id, club_id FROM inventario_ventas WHERE id = ?");
    $stmtExist->execute([$venta_id]);
    $ventaRow = $stmtExist->fetch();
    if (!$ventaRow) {
        throw new Exception("Venta ID #$venta_id no encontrada.");
    }

    $club_id = !empty($data['club_id']) ? intval($data['club_id']) : intval($ventaRow['club_id']);
    $usuario_id = intval($data['usuario_id'] ?? 0);
    $jugador_id = !empty($data['jugador_id']) ? intval($data['jugador_id']) : null;
    $total = floatval($data['total'] ?? 0);
    $metodo_pago = $data['metodo_pago'] ?? 'Efectivo';
    $items = $data['items'];

    $fechaRaw = trim($data['fecha'] ?? '');
    $timeParsed = strtotime($fechaRaw);
    if ($timeParsed !== false && $timeParsed > 0) {
        $fecha = date('Y-m-d H:i:s', $timeParsed);
    } else {
        $fecha = date('Y-m-d H:i:s');
    }

    // 1. Obtener detalles antiguos para devolver stock a inventario (solo para productos físicos)
    $stmtOldItems = $pdo->prepare("SELECT vd.producto_id, vd.cantidad, p.tipo 
                                   FROM inventario_venta_detalles vd 
                                   LEFT JOIN inventario_productos p ON vd.producto_id = p.id 
                                   WHERE vd.venta_id = ?");
    $stmtOldItems->execute([$venta_id]);
    $oldItems = $stmtOldItems->fetchAll(PDO::FETCH_ASSOC);

    foreach ($oldItems as $old) {
        $p_id = intval($old['producto_id']);
        $qty = intval($old['cantidad']);
        $tipo = isset($old['tipo']) ? strtolower(trim($old['tipo'])) : 'producto';
        if ($p_id > 0 && $tipo !== 'servicio') {
            $stmtRestore = $pdo->prepare("UPDATE inventario_productos SET stock_actual = stock_actual + ? WHERE id = ?");
            $stmtRestore->execute([$qty, $p_id]);
        }
    }

    // 2. Eliminar detalles antiguos
    $stmtDelDetalles = $pdo->prepare("DELETE FROM inventario_venta_detalles WHERE venta_id = ?");
    $stmtDelDetalles->execute([$venta_id]);

    // 3. Actualizar la cabecera de la venta
    $stmtUpdateVenta = $pdo->prepare("UPDATE inventario_ventas SET usuario_id = ?, jugador_id = ?, total = ?, metodo_pago = ?, fecha = ? WHERE id = ?");
    $stmtUpdateVenta->execute([$usuario_id, $jugador_id, $total, $metodo_pago, $fecha, $venta_id]);

    // Si está vinculada a una reserva de cancha, sincronizar fecha y hora en la reserva
    $linkedReservaId = intval($ventaRow['reserva_id'] ?? 0);
    if ($linkedReservaId > 0 && $timeParsed > 0) {
        $fechaSoloDate = date('Y-m-d', $timeParsed);
        $horaInicioNew = date('H:i:s', $timeParsed);
        if ($horaInicioNew !== '00:00:00') {
            $stmtUpdRes = $pdo->prepare("UPDATE reservas_cancha SET fecha = ?, hora_inicio = ?, precio = ? WHERE id = ?");
            $stmtUpdRes->execute([$fechaSoloDate, $horaInicioNew, $total, $linkedReservaId]);
        } else {
            $stmtUpdRes = $pdo->prepare("UPDATE reservas_cancha SET fecha = ?, precio = ? WHERE id = ?");
            $stmtUpdRes->execute([$fechaSoloDate, $total, $linkedReservaId]);
        }
    }

    // 4. Consolidar ítems para evitar filas duplicadas (especialmente producto_id = 0)
    $consolidatedItems = [];
    foreach ($items as $item) {
        $p_id = intval($item['id'] ?? 0);
        $cantidad = intval($item['cantidad'] ?? 1);
        $precio_unit = floatval($item['precio_venta'] ?? 0);
        $key = ($p_id === 0) ? 'cancha_0' : ('prod_' . $p_id);

        if (!isset($consolidatedItems[$key])) {
            $consolidatedItems[$key] = [
                'id' => $p_id,
                'cantidad' => $cantidad,
                'precio_venta' => $precio_unit
            ];
        } else {
            if ($p_id > 0) {
                $consolidatedItems[$key]['cantidad'] += $cantidad;
            }
        }
    }

    // Insertar nuevos detalles y descontar nuevo stock
    foreach ($consolidatedItems as $item) {
        $p_id = intval($item['id'] ?? 0);
        $cantidad = intval($item['cantidad'] ?? 1);
        $precio_unit = floatval($item['precio_venta'] ?? 0);

        // Si es un producto de inventario (p_id > 0), verificar stock
        if ($p_id > 0) {
            $stmtCheck = $pdo->prepare("SELECT stock_actual, nombre, club_id, tipo FROM inventario_productos WHERE id = ? FOR UPDATE");
            $stmtCheck->execute([$p_id]);
            $producto = $stmtCheck->fetch();

            if ($producto) {
                $prodClubId = intval($producto['club_id']);
                $esServicio = isset($producto['tipo']) && strtolower(trim($producto['tipo'])) === 'servicio';

                if (!$esServicio) {
                    if ($producto['stock_actual'] < $cantidad) {
                        throw new Exception("Stock insuficiente para: " . $producto['nombre'] . " (Disponible: " . $producto['stock_actual'] . ")");
                    }

                    // Actualizar Stock
                    $stmtUpdate = $pdo->prepare("UPDATE inventario_productos SET stock_actual = stock_actual - ? WHERE id = ?");
                    $stmtUpdate->execute([$cantidad, $p_id]);

                    // Registrar movimiento (kardex)
                    $stmtMov = $pdo->prepare("INSERT INTO inventario_movimientos (club_id, producto_id, tipo, cantidad, motivo, referencia_id) VALUES (?, ?, 'salida', ?, 'Edición Venta', ?)");
                    $stmtMov->execute([$prodClubId, $p_id, $cantidad, $venta_id]);
                }
            } else {
                $p_id = 0;
            }
        }

        // Insertar Detalle
        $stmtDetalle = $pdo->prepare("INSERT INTO inventario_venta_detalles (venta_id, producto_id, cantidad, precio_unitario) VALUES (?, ?, ?, ?)");
        $stmtDetalle->execute([$venta_id, $p_id, $cantidad, $precio_unit]);
    }

    $pdo->commit();
    ob_clean();
    echo json_encode(["success" => true, "venta_id" => $venta_id, "message" => "Venta actualizada con éxito"]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Exception $eRB) {}
    }
    ob_clean();
    http_response_code(200);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
