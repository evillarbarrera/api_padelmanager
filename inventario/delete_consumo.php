<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

require_once '../db.php';

$data = json_decode(file_get_contents("php://input"));
$id = isset($data->id) ? (int)$data->id : 0;

if ($id <= 0) {
    echo json_encode(["success" => false, "message" => "ID inválido"]);
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM reservas_consumos WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(["success" => true, "message" => "Consumo eliminado"]);
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
