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

$data = json_decode(file_get_contents("php://input"), true) ?: [];
$reserva_id = (int)($data['reserva_id'] ?? 0);
$reserva_ids = [];
if (!empty($data['reserva_ids']) && is_array($data['reserva_ids'])) {
    $reserva_ids = array_values(array_filter(array_map('intval', $data['reserva_ids'])));
}
if ($reserva_id > 0 && !in_array($reserva_id, $reserva_ids)) {
    $reserva_ids[] = $reserva_id;
}

if (empty($reserva_ids)) {
    ob_clean();
    http_response_code(400);
    echo json_encode(["error" => "ID de reserva necesario"]);
    exit;
}

$cancel_all_torneo = !empty($data['cancel_all_torneo']);
$primary_id = $reserva_ids[0];

// 1. Obtener datos antes de cancelar para el correo y validaciones
$sql = "SELECT r.*, c.club_id FROM reservas_cancha r LEFT JOIN canchas c ON r.cancha_id = c.id WHERE r.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $primary_id);
$stmt->execute();
$reserva = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$reserva) {
    ob_clean();
    http_response_code(404);
    echo json_encode(["error" => "Reserva no encontrada"]);
    exit;
}

$cancha_id = (int)($data['cancha_id'] ?? $reserva['cancha_id'] ?? 0);
$fecha = !empty($data['fecha']) ? $conn->real_escape_string($data['fecha']) : $reserva['fecha'];
$club_id = (int)($reserva['club_id'] ?? 0);
$torneo_id = (int)($data['torneo_id'] ?? $reserva['torneo_id'] ?? 0);

$isAmericano = (
    $torneo_id > 0 ||
    ($reserva['tipo_reserva'] ?? '') === 'americano' ||
    ($reserva['estado'] ?? '') === 'Americano' ||
    stripos($reserva['nombre_externo'] ?? '', 'americano') !== false
);

// 2. Anular y eliminar ventas e inventario asociados para todos los IDs
$idsListStr = implode(',', $reserva_ids);
try {
    $resVenta = $conn->query("SELECT id FROM inventario_ventas WHERE reserva_id IN ($idsListStr)");
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
                    $item_club_id = intval($item['club_id']);
                    $tipo = isset($item['tipo']) ? strtolower(trim($item['tipo'])) : 'producto';
                    
                    if ($p_id > 0 && $tipo !== 'servicio') {
                        $conn->query("UPDATE inventario_productos SET stock_actual = stock_actual + $qty WHERE id = $p_id");
                        $stmtMov = $conn->prepare("INSERT INTO inventario_movimientos (club_id, producto_id, tipo, cantidad, motivo, referencia_id) 
                                                 VALUES (?, ?, 'entrada', ?, 'Cancelación Reserva', ?)");
                        if ($stmtMov) {
                            $stmtMov->bind_param("iidi", $item_club_id, $p_id, $qty, $vIdDel);
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
    $conn->query("UPDATE reservas_consumos SET pagado = 0 WHERE reserva_id IN ($idsListStr)");
} catch (Throwable $eVenta) {
    error_log("Error revirtiendo venta de reserva cancelada: " . $eVenta->getMessage());
}

// 3. Procesar cancelación/eliminación según tipo de reserva
if ($isAmericano) {
    if ($cancel_all_torneo && $torneo_id > 0) {
        // Liberar todas las pistas asociadas a este torneo
        $conn->query("DELETE FROM reservas_cancha WHERE torneo_id = $torneo_id");
        if ($club_id > 0 && !empty($fecha)) {
            $conn->query("DELETE r FROM reservas_cancha r 
                          JOIN canchas c ON r.cancha_id = c.id 
                          WHERE c.club_id = $club_id AND r.fecha = '$fecha' 
                            AND (r.tipo_reserva = 'americano' OR r.estado = 'Americano' OR LOWER(r.nombre_externo) LIKE '%americano%')");
        }
    } else {
        // Liberar esta pista específica para este Americano
        if ($cancha_id > 0 && !empty($fecha)) {
            $conn->query("DELETE FROM reservas_cancha 
                          WHERE cancha_id = $cancha_id 
                            AND fecha = '$fecha' 
                            AND (
                                ($torneo_id > 0 AND torneo_id = $torneo_id)
                                OR tipo_reserva = 'americano' 
                                OR estado = 'Americano' 
                                OR LOWER(nombre_externo) LIKE '%americano%'
                                OR id IN ($idsListStr)
                            )");
        }
    }

    // Asegurar eliminación de todos los IDs explícitos
    $conn->query("DELETE FROM reservas_cancha WHERE id IN ($idsListStr)");
    $success = true;
} else {
    // Reserva normal: marcar como cancelada
    $sqlUpdate = "UPDATE reservas_cancha SET estado = 'Cancelada', venta_generada = 0, pagado = 0 WHERE id IN ($idsListStr)";
    $success = $conn->query($sqlUpdate);
}

if ($success) {
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
        notificarReservaCancha($conn, $primary_id, 'cancelacion');
    } catch (Throwable $e) {
        error_log("Error enviando notificacion de cancelacion de reserva: " . $e->getMessage());
    }
} else {
    ob_clean();
    http_response_code(500);
    echo json_encode(["error" => "Error al cancelar"]);
}
?>
