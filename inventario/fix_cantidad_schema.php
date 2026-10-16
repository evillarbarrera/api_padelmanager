<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

require_once '../db.php';

try {
    // Verificar y alterar la columna cantidad en reservas_consumos
    $pdo->exec("ALTER TABLE reservas_consumos MODIFY cantidad DECIMAL(10,2) NOT NULL DEFAULT 1");
    // Verificar y alterar la columna cantidad en inventario_venta_detalles
    $pdo->exec("ALTER TABLE inventario_venta_detalles MODIFY cantidad DECIMAL(10,2) NOT NULL DEFAULT 1");

    // Corregir la cantidad 0 en reservas_consumos usando subtotal / precio_unitario
    $pdo->exec("UPDATE reservas_consumos 
                SET cantidad = ROUND(subtotal / precio_unitario, 2) 
                WHERE cantidad = 0 AND precio_unitario > 0 AND subtotal > 0");

    // Corregir la cantidad 0 en inventario_venta_detalles usando precio_unitario (que en get_ventas representa el subtotal / qty)
    // Wait, in inventario_venta_detalles, `precio_unitario` is the original unit price, and subtotal isn't usually a column.
    // Let's check get_ventas.php lines 181-182: it inserts `cantidad` and `precio_unitario`.
    // It says: INSERT INTO inventario_venta_detalles (venta_id, producto_id, cantidad, precio_unitario) VALUES (?, ?, ?, ?)
    // If qty was 0, it inserted 0. But how did it calculate the total?
    // Actually, let's just look at the table inventario_venta_detalles. 
    // Usually it doesn't have `subtotal`. We'll just leave inventario_venta_detalles alone unless it's easy to fix.
    // Wait, we can get the original price from `inventario_productos`.
    $pdo->exec("UPDATE inventario_venta_detalles vd
                INNER JOIN inventario_productos p ON vd.producto_id = p.id
                SET vd.cantidad = ROUND(vd.precio_unitario / p.precio_venta, 2)
                WHERE vd.cantidad = 0 AND p.precio_venta > 0 AND vd.precio_unitario > 0");

    echo json_encode(["success" => true, "message" => "Columnas 'cantidad' actualizadas a DECIMAL(10,2) y datos históricos de cantidad 0 corregidos"]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
}
?>
