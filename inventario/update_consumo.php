<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../db.php';

$data = json_decode(file_get_contents("php://input"));

if (!$data || !isset($data->id) || !isset($data->cantidad)) {
    echo json_encode(["success" => false, "message" => "Datos incompletos: se requiere id y cantidad"]);
    exit;
}

$id       = (int)$data->id;
$cantidad = (float)$data->cantidad;

if ($id <= 0) {
    echo json_encode(["success" => false, "message" => "ID de consumo inválido"]);
    exit;
}

if ($cantidad <= 0) {
    // Si la cantidad llega a 0 o menos, eliminar la fila
    try {
        $stmt = $pdo->prepare("DELETE FROM reservas_consumos WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(["success" => true, "deleted" => true, "message" => "Consumo eliminado por cantidad 0"]);
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
    exit;
}

try {
    // Recalcular subtotal con el precio_unitario guardado
    $stmt = $pdo->prepare("SELECT precio_unitario FROM reservas_consumos WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(["success" => false, "message" => "Consumo no encontrado"]);
        exit;
    }

    $precio_unitario = (float)$row['precio_unitario'];
    $nuevo_subtotal  = $precio_unitario * $cantidad;

    $stmt = $pdo->prepare("UPDATE reservas_consumos SET cantidad = ?, subtotal = ? WHERE id = ?");
    $stmt->execute([$cantidad, $nuevo_subtotal, $id]);

    echo json_encode([
        "success"        => true,
        "message"        => "Consumo actualizado",
        "cantidad"       => $cantidad,
        "subtotal"       => $nuevo_subtotal
    ]);

} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
