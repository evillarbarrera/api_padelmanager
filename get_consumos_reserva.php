<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

require_once '../db.php';

$reserva_id = isset($_GET['reserva_id']) ? (int)$_GET['reserva_id'] : 0;

if ($reserva_id <= 0) {
    echo json_encode(["success" => false, "message" => "ID de reserva inválido"]);
    exit;
}

try {
    $sql = "SELECT c.*, p.nombre as producto_nombre 
            FROM reservas_consumos c
            JOIN inventario_productos p ON c.producto_id = p.id
            WHERE c.reserva_id = ?
            ORDER BY c.fecha DESC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$reserva_id]);
    $consumos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "consumos" => $consumos
    ]);

} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
