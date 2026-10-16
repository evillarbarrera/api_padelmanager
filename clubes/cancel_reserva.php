<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$reserva_id = $data['reserva_id'] ?? 0;

if (!$reserva_id) {
    ob_clean();
    http_response_code(400);
    echo json_encode(["error" => "ID de reserva necesario"]);
    exit;
}

// 1. Obtener datos antes de cancelar para el correo
$sql = "SELECT * FROM reservas_cancha WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $reserva_id);
$stmt->execute();
$reserva = $stmt->get_result()->fetch_assoc();

if (!$reserva) {
    ob_clean();
    http_response_code(404);
    echo json_encode(["error" => "Reserva no encontrada"]);
    exit;
}

// 2. Anular y eliminar ventas e inventario asociados
try {
    $resVenta = $conn->query("SELECT id FROM inventario_ventas WHERE reserva_id = $reserva_id");
    if ($resVenta) {
        while ($rowV = $resVenta->fetch_assoc()) {
            $vIdDel = intval($rowV['id']);
            
            // Revertir stock de productos físicos consumidos
            $stmtItems = $conn->query("SELECT vd.producto_id, vd.cantidad, v.club_id, p.tipo 
                                       FROM inventario_venta_detalles vd 
                                       JOIN inventario_ventas v ON vd.venta_id = v.id 
                                       LEFT JOIN inventario_productos p ON vd.producto_id = p.id 
                                       WHERE vd.venta_id = $vIdDel");
            if ($stmtItems) {
                while ($item = $stmtItems->fetch_assoc()) {
                    $p_id = intval($item['producto_id']);
                    $qty = floatval($item['cantidad']);
                    $club_id = intval($item['club_id']);
                    $tipo = isset($item['tipo']) ? strtolower(trim($item['tipo'])) : 'producto';
                    
                    if ($p_id > 0 && $tipo !== 'servicio') {
                        $conn->query("UPDATE inventario_productos SET stock_actual = stock_actual + $qty WHERE id = $p_id");
                        $stmtMov = $conn->prepare("INSERT INTO inventario_movimientos (club_id, producto_id, tipo, cantidad, motivo, referencia_id) 
                                                 VALUES (?, ?, 'entrada', ?, 'Cancelación Reserva', ?)");
                        if ($stmtMov) {
                            $stmtMov->bind_param("iidi", $club_id, $p_id, $qty, $vIdDel);
                            $stmtMov->execute();
                        }
                    }
                }
            }
            
            $conn->query("DELETE FROM inventario_venta_detalles WHERE venta_id = $vIdDel");
            $conn->query("DELETE FROM inventario_ventas WHERE id = $vIdDel");
        }
    }
    
    // Revertir consumos asociados
    $conn->query("UPDATE reservas_consumos SET pagado = 0 WHERE reserva_id = $reserva_id");
} catch (Throwable $eVenta) {
    error_log("Error revirtiendo venta de reserva cancelada #$reserva_id: " . $eVenta->getMessage());
}

// 3. Cancelar reserva y reiniciar estado de pago/venta
$sqlUpdate = "UPDATE reservas_cancha SET estado = 'Cancelada', venta_generada = 0, pagado = 0 WHERE id = ?";
$stmtUpdate = $conn->prepare($sqlUpdate);
$stmtUpdate->bind_param("i", $reserva_id);

if ($stmtUpdate->execute()) {
    // Sincronización bidireccional con Liga
    if (!empty($reserva['liga_partido_id'])) {
        $lpId = intval($reserva['liga_partido_id']);
        $conn->query("UPDATE liga_partidos SET cancha_id = NULL, fecha_hora = NULL WHERE id = $lpId");
    }

    // 1. Devolver respuesta JSON de inmediato (0.1s)
    @ob_clean();
    $jsonResponse = json_encode(["success" => true]);
    echo $jsonResponse;

    // 2. Liberar la conexión HTTP de inmediato
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ignore_user_abort(true);
        @header("Connection: close");
        @header("Content-Length: " . strlen($jsonResponse));
        @flush();
    }

    // 3. Procesar el envío de correo en segundo plano
    try {
        require_once "../system/mail_service.php";
        notificarReservaCancha($conn, $reserva_id, 'cancelacion');
    } catch (Throwable $e) {
        error_log("Error enviando notificacion de cancelacion de reserva: " . $e->getMessage());
    }
} else {
    ob_clean();
    http_response_code(500);
    echo json_encode(["error" => "Error al cancelar"]);
}
?>
