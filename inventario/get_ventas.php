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
    // Obtener cabeceras de ventas con detalles de usuario si es posible
    $sql = "SELECT v.*, u.nombre as vendedor 
            FROM inventario_ventas v
            LEFT JOIN usuarios u ON v.usuario_id = u.id
            WHERE v.club_id = ? AND DATE(v.fecha) BETWEEN ? AND ?
            ORDER BY v.fecha DESC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$club_id, $fecha_inicio, $fecha_fin]);
    $ventas = $stmt->fetchAll();
    
    // Para cada venta, obtener sus items
    foreach ($ventas as &$venta) {
        $stmtItems = $pdo->prepare("SELECT vd.*, COALESCE(p.nombre, 'Alquiler de Cancha / Servicio') as producto_nombre 
                                   FROM inventario_venta_detalles vd
                                   LEFT JOIN inventario_productos p ON vd.producto_id = p.id
                                   WHERE vd.venta_id = ?");
        $stmtItems->execute([$venta['id']]);
        $venta['items'] = $stmtItems->fetchAll();
    }
    
    echo json_encode(["success" => true, "ventas" => $ventas]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Error al obtener ventas: " . $e->getMessage()]);
}
