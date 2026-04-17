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

if (!$data || !isset($data['club_id']) || !isset($data['items']) || empty($data['items'])) {
    http_response_code(400);
    echo json_encode(["error" => "Faltan datos de la venta"]);
    exit;
}

$club_id = $data['club_id'];
$usuario_id = $data['usuario_id'] ?? 0;
$total = $data['total'] ?? 0;
$metodo_pago = $data['metodo_pago'] ?? 'Efectivo';
$items = $data['items'];

try {
    $pdo->beginTransaction();

    // 1. Crear Cabecera de Venta
    $stmtVenta = $pdo->prepare("INSERT INTO inventario_ventas (club_id, usuario_id, total, metodo_pago) VALUES (?, ?, ?, ?)");
    $stmtVenta->execute([$club_id, $usuario_id, $total, $metodo_pago]);
    $venta_id = $pdo->lastInsertId();

    foreach ($items as $item) {
        $p_id = $item['id'];
        $cantidad = intval($item['cantidad']);
        $precio_unit = $item['precio_venta'];

        // 2. Verificar Stock y Club del producto (Bloqueo para escritura)
        $stmtCheck = $pdo->prepare("SELECT stock_actual, nombre FROM inventario_productos WHERE id = ? AND club_id = ? FOR UPDATE");
        $stmtCheck->execute([$p_id, $club_id]);
        $producto = $stmtCheck->fetch();

        if (!$producto) {
            throw new Exception("El producto ID $p_id no pertenece a este club.");
        }
        if ($producto['stock_actual'] < $cantidad) {
            throw new Exception("Stock insuficiente para: " . $producto['nombre']);
        }

        // 3. Insertar Detalle
        $stmtDetalle = $pdo->prepare("INSERT INTO inventario_venta_detalles (venta_id, producto_id, cantidad, precio_unitario) VALUES (?, ?, ?, ?)");
        $stmtDetalle->execute([$venta_id, $p_id, $cantidad, $precio_unit]);

        // 4. Actualizar Stock Físico
        $stmtUpdate = $pdo->prepare("UPDATE inventario_productos SET stock_actual = stock_actual - ? WHERE id = ?");
        $stmtUpdate->execute([$cantidad, $p_id]);

        // 5. Registrar Movimiento (Kardex)
        $stmtMov = $pdo->prepare("INSERT INTO inventario_movimientos (club_id, producto_id, tipo, cantidad, motivo, referencia_id) VALUES (?, ?, 'salida', ?, 'Venta', ?)");
        $stmtMov->execute([$club_id, $p_id, $cantidad, $venta_id]);
    }

    $pdo->commit();
    echo json_encode(["success" => true, "venta_id" => $venta_id]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(["error" => $e->getMessage()]);
}
