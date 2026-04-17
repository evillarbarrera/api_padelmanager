<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../db.php';

$data = json_decode(file_get_contents("php://input"));

if (!$data || !isset($data->reserva_id) || !isset($data->producto_id)) {
    echo json_encode(["success" => false, "message" => "Datos incompletos"]);
    exit;
}

$reserva_id = (int)$data->reserva_id;
$producto_id = (int)$data->producto_id;
$jugador_n = isset($data->jugador_n) ? (int)$data->jugador_n : 1;
$cantidad = isset($data->cantidad) ? (int)$data->cantidad : 1;

try {
    // 1. Obtener precio del producto
    $stmt = $pdo->prepare("SELECT precio_venta, nombre FROM inventario_productos WHERE id = ?");
    $stmt->execute([$producto_id]);
    $producto = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$producto) {
        throw new Exception("Producto no encontrado");
    }

    $precio_unitario = $producto['precio_venta'];
    $subtotal = $precio_unitario * $cantidad;

    // 2. Insertar consumo
    $sql = "INSERT INTO reservas_consumos (reserva_id, jugador_n, producto_id, cantidad, precio_unitario, subtotal, pagado) 
            VALUES (?, ?, ?, ?, ?, ?, 0)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$reserva_id, $jugador_n, $producto_id, $cantidad, $precio_unitario, $subtotal]);

    // 3. (Opcional) Descontar stock si se prefiere en este momento (pero suele ser al pagar)
    // Por ahora solo registramos el pendiente.

    echo json_encode([
        "success" => true, 
        "message" => "Consumo agregado con éxito",
        "consumo_id" => $pdo->lastInsertId()
    ]);

} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
