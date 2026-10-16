<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

if (!$data || !isset($data['id'])) {
    http_response_code(400);
    echo json_encode(["error" => "ID de venta requerido"]);
    exit;
}

$venta_id = $data['id'];

try {
    $pdo->beginTransaction();

    // 1. Obtener detalles para devolver el stock (solo productos físicos)
    $stmtItems = $pdo->prepare("SELECT vd.producto_id, vd.cantidad, v.club_id, p.tipo FROM inventario_venta_detalles vd 
                                JOIN inventario_ventas v ON vd.venta_id = v.id
                                LEFT JOIN inventario_productos p ON vd.producto_id = p.id
                                WHERE vd.venta_id = ?");
    $stmtItems->execute([$venta_id]);
    $items = $stmtItems->fetchAll();

    foreach ($items as $item) {
        $p_id = intval($item['producto_id']);
        $qty = $item['cantidad'];
        $club_id = $item['club_id'];
        $tipo = isset($item['tipo']) ? strtolower(trim($item['tipo'])) : 'producto';

        if ($p_id > 0 && $tipo !== 'servicio') {
            // 2. Devolver Stock
            $stmtRestore = $pdo->prepare("UPDATE inventario_productos SET stock_actual = stock_actual + ? WHERE id = ?");
            $stmtRestore->execute([$qty, $p_id]);

            // 3. Registrar Movimiento de Ajuste
            $stmtMov = $pdo->prepare("INSERT INTO inventario_movimientos (club_id, producto_id, tipo, cantidad, motivo, referencia_id) 
                                     VALUES (?, ?, 'entrada', ?, 'Anulación Venta', ?)");
            $stmtMov->execute([$club_id, $p_id, $qty, $venta_id]);
        }
    }

    // 4. Eliminar la venta (Cascada eliminará detalles)
    $stmtDelete = $pdo->prepare("DELETE FROM inventario_ventas WHERE id = ?");
    $stmtDelete->execute([$venta_id]);

    $pdo->commit();
    echo json_encode(["success" => true]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(["error" => "Error al anular venta: " . $e->getMessage()]);
}
