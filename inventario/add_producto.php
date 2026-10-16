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

if (!$data || !isset($data['club_id']) || !isset($data['nombre']) || !isset($data['precio_venta'])) {
    http_response_code(400);
    echo json_encode(["error" => "Faltan campos obligatorios (club_id, nombre, precio_venta)"]);
    exit;
}

try {
    $tipo = $data['tipo'] ?? 'producto';
    $stock_actual = ($tipo === 'servicio') ? 0 : ($data['stock_actual'] ?? 0);
    $stock_minimo = ($tipo === 'servicio') ? 0 : ($data['stock_minimo'] ?? 5);

    if (isset($data['id']) && $data['id'] > 0) {
        // ACTUALIZAR PRODUCTO EXISTENTE
        $stmt = $pdo->prepare("UPDATE inventario_productos SET nombre = ?, categoria = ?, precio_costo = ?, precio_venta = ?, stock_actual = ?, stock_minimo = ?, tipo = ? WHERE id = ? AND club_id = ?");
        $stmt->execute([
            $data['nombre'],
            $data['categoria'] ?? 'Otros',
            $data['precio_costo'] ?? 0,
            $data['precio_venta'],
            $stock_actual,
            $stock_minimo,
            $tipo,
            $data['id'],
            $data['club_id']
        ]);
        echo json_encode(["success" => true, "id" => $data['id'], "message" => "Producto actualizado"]);
    } else {
        // INSERTAR NUEVO PRODUCTO
        $stmt = $pdo->prepare("INSERT INTO inventario_productos (club_id, nombre, categoria, precio_costo, precio_venta, stock_actual, stock_minimo, tipo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['club_id'],
            $data['nombre'],
            $data['categoria'] ?? 'Otros',
            $data['precio_costo'] ?? 0,
            $data['precio_venta'],
            $stock_actual,
            $stock_minimo,
            $tipo
        ]);
        
        $id = $pdo->lastInsertId();
        
        // Registrar movimiento inicial si hay stock
        if (($data['stock_actual'] ?? 0) > 0) {
            $stmtMov = $pdo->prepare("INSERT INTO inventario_movimientos (club_id, producto_id, tipo, cantidad, motivo) VALUES (?, ?, 'entrada', ?, 'Stock Inicial')");
            $stmtMov->execute([$data['club_id'], $id, $data['stock_actual']]);
        }
        
        echo json_encode(["success" => true, "id" => $id, "message" => "Producto creado"]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Error al guardar producto: " . $e->getMessage()]);
}
