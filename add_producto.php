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
    $stmt = $pdo->prepare("INSERT INTO inventario_productos (club_id, nombre, categoria, precio_costo, precio_venta, stock_actual, stock_minimo) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $data['club_id'],
        $data['nombre'],
        $data['categoria'] ?? 'Otros',
        $data['precio_costo'] ?? 0,
        $data['precio_venta'],
        $data['stock_actual'] ?? 0,
        $data['stock_minimo'] ?? 5
    ]);
    
    $id = $pdo->lastInsertId();
    
    // Registrar movimiento inicial si hay stock
    if (($data['stock_actual'] ?? 0) > 0) {
        $stmtMov = $pdo->prepare("INSERT INTO inventario_movimientos (club_id, producto_id, tipo, cantidad, motivo) VALUES (?, ?, 'entrada', ?, 'Stock Inicial')");
        $stmtMov->execute([$data['club_id'], $id, $data['stock_actual']]);
    }
    
    echo json_encode(["success" => true, "id" => $id]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Error al guardar producto: " . $e->getMessage()]);
}
