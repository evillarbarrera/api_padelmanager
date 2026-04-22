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

// SILENT SCHEMA FIX
function ensureColumnReservas($conn, $column, $definition) {
    if ($column === 'usuario_id') {
        $conn->query("ALTER TABLE reservas_cancha MODIFY usuario_id INT NULL");
        return;
    }
    $check = $conn->query("SHOW COLUMNS FROM reservas_cancha LIKE '$column'");
    if ($check && $check->num_rows == 0) {
        $conn->query("ALTER TABLE reservas_cancha ADD `$column` $definition");
    }
}
ensureColumnReservas($conn, 'usuario_id', "INT NULL");
ensureColumnReservas($conn, 'jugador2_id', "INT NULL");
ensureColumnReservas($conn, 'jugador3_id', "INT NULL");
ensureColumnReservas($conn, 'jugador4_id', "INT NULL");
ensureColumnReservas($conn, 'nombre_externo', "VARCHAR(255) NULL");
ensureColumnReservas($conn, 'nombre_externo2', "VARCHAR(255) NULL");
ensureColumnReservas($conn, 'nombre_externo3', "VARCHAR(255) NULL");
ensureColumnReservas($conn, 'nombre_externo4', "VARCHAR(255) NULL");
ensureColumnReservas($conn, 'pagado', "TINYINT(1) DEFAULT 0");
ensureColumnReservas($conn, 'metodo_pago', "VARCHAR(20) DEFAULT 'total'");
ensureColumnReservas($conn, 'pago_p1', "TINYINT(1) DEFAULT 1");
ensureColumnReservas($conn, 'pago_p2', "TINYINT(1) DEFAULT 0");
ensureColumnReservas($conn, 'pago_p3', "TINYINT(1) DEFAULT 0");
ensureColumnReservas($conn, 'pago_p4', "TINYINT(1) DEFAULT 0");
ensureColumnReservas($conn, 'marcador', "VARCHAR(50) NULL");
ensureColumnReservas($conn, 'categoria', "VARCHAR(50) NULL");
ensureColumnReservas($conn, 'resultado_registrado', "TINYINT(1) DEFAULT 0");
ensureColumnReservas($conn, 'id_ganador', "INT NULL");

$data = json_decode(file_get_contents("php://input"), true);

$id = $data['id'] ?? 0;
$cancha_id = $data['cancha_id'] ?? 0;
$fecha = $data['fecha'] ?? '';
$hora_inicio = $data['hora_inicio'] ?? '';
$hora_fin = $data['hora_fin'] ?? '';

$metodo_pago = strtolower(trim($data['metodo_pago'] ?? 'total'));
$pagos = $data['pagos'] ?? [];

// Leer pagos (prioridad a los campos aplanados enviados desde el front)
$pago_p1 = isset($data['pago_p1']) ? intval($data['pago_p1']) : (isset($pagos['p1']) && $pagos['p1'] ? 1 : 0);
$pago_p2 = isset($data['pago_p2']) ? intval($data['pago_p2']) : (isset($pagos['p2']) && $pagos['p2'] ? 1 : 0);
$pago_p3 = isset($data['pago_p3']) ? intval($data['pago_p3']) : (isset($pagos['p3']) && $pagos['p3'] ? 1 : 0);
$pago_p4 = isset($data['pago_p4']) ? intval($data['pago_p4']) : (isset($pagos['p4']) && $pagos['p4'] ? 1 : 0);

// Extraer jugadores con seguridad (usar null si es 0 para evitar fallos de Foreign Key)
$jugador_id = !empty($data['jugador_id']) ? intval($data['jugador_id']) : null;
$jugador2_id = !empty($data['jugador2_id']) ? intval($data['jugador2_id']) : null;
$jugador3_id = !empty($data['jugador3_id']) ? intval($data['jugador3_id']) : null;
$jugador4_id = !empty($data['jugador4_id']) ? intval($data['jugador4_id']) : null;

$nombre_externo = $data['nombre_externo'] ?? '';
$nombre_externo2 = $data['nombre_externo2'] ?? '';
$nombre_externo3 = $data['nombre_externo3'] ?? '';
$nombre_externo4 = $data['nombre_externo4'] ?? '';

$pagado = intval($data['pagado'] ?? 0);
if ($metodo_pago === 'proporcional') {
    $allPaid = ($pago_p1 == 1);
    if (!empty($data['nombre_externo2']) || !empty($data['jugador2_id'])) $allPaid = $allPaid && ($pago_p2 == 1);
    if (!empty($data['nombre_externo3']) || !empty($data['jugador3_id'])) $allPaid = $allPaid && ($pago_p3 == 1);
    if (!empty($data['nombre_externo4']) || !empty($data['jugador4_id'])) $allPaid = $allPaid && ($pago_p4 == 1);
    $pagado = $allPaid ? 1 : 0;
}

if (!$id || !$cancha_id || !$fecha || !$hora_inicio) {
    ob_clean();
    http_response_code(400);
    echo json_encode(["error" => "Faltan datos obligatorios"]);
    exit;
}

// 1. Validar disponibilidad
$sqlCheck = "SELECT id FROM reservas_cancha 
             WHERE cancha_id = ? AND fecha = ? AND id != ?
             AND ((hora_inicio < ? AND hora_fin > ?) OR (hora_inicio < ? AND hora_fin > ?))
             AND estado != 'Cancelada'";
$stmtCheck = $conn->prepare($sqlCheck);
$stmtCheck->bind_param("issssss", $cancha_id, $fecha, $id, $hora_fin, $hora_inicio, $hora_inicio, $hora_inicio);
$stmtCheck->execute();
if ($stmtCheck->get_result()->num_rows > 0) {
    ob_clean();
    http_response_code(409);
    echo json_encode(["error" => "Choque de horario con otra reserva"]);
    exit;
}

// 2. Actualizar reserva
$sqlUpdate = "UPDATE reservas_cancha SET 
              cancha_id = ?, 
              usuario_id = ?, jugador2_id = ?, jugador3_id = ?, jugador4_id = ?, 
              nombre_externo = ?, nombre_externo2 = ?, nombre_externo3 = ?, nombre_externo4 = ?, 
              fecha = ?, hora_inicio = ?, hora_fin = ?, precio = ?, pagado = ?, estado = ?,
              metodo_pago = ?, pago_p1 = ?, pago_p2 = ?, pago_p3 = ?, pago_p4 = ?
              WHERE id = ?";

$stmt = $conn->prepare($sqlUpdate);
$stmt->bind_param("iiiiisssssssdissiiiii", 
    $cancha_id, 
    $jugador_id, $jugador2_id, $jugador3_id, $jugador4_id,
    $nombre_externo, $nombre_externo2, $nombre_externo3, $nombre_externo4,
    $fecha, $hora_inicio, $hora_fin, $data['precio'], $pagado, $data['estado'],
    $metodo_pago, $pago_p1, $pago_p2, $pago_p3, $pago_p4, $id
);

if ($stmt->execute()) {
    $integrationError = null;
    // --- INTEGRACIÓN CON INVENTARIO Y VENTAS ---
    if ($pagado == 1) {
        try {
            $checkVenta = $conn->query("SELECT venta_generada FROM reservas_cancha WHERE id = $id")->fetch_assoc();
            if ($checkVenta && intval($checkVenta['venta_generada']) == 0) {
                
                $clubRow = $conn->query("SELECT club_id FROM canchas WHERE id = $cancha_id")->fetch_assoc();
                $club_id = $clubRow ? $clubRow['club_id'] : 0;
                
                if ($club_id > 0) {
                    $conn->begin_transaction();

                    $consumos_res = $conn->query("SELECT * FROM reservas_consumos WHERE reserva_id = $id AND pagado = 0");
                    $total_consumos = 0;
                    $items_consumo = [];
                    while($c = $consumos_res->fetch_assoc()) {
                        $total_consumos += floatval($c['subtotal']);
                        $items_consumo[] = $c;
                    }
                    
                    $precio_cancha = floatval($data['precio']);
                    $total_venta = $precio_cancha + $total_consumos;

                    $metodo_pago_v = ($metodo_pago == 'proporcional') ? 'Varios' : 'Reserva';
                    $ext_user_id = intval($jugador_id ?? 0);
                    
                    $stmtVenta = $conn->prepare("INSERT INTO inventario_ventas (club_id, usuario_id, total, metodo_pago, reserva_id) VALUES (?, ?, ?, ?, ?)");
                    if ($stmtVenta) {
                        $stmtVenta->bind_param("iidsi", $club_id, $ext_user_id, $total_venta, $metodo_pago_v, $id);
                        if ($stmtVenta->execute()) {
                            $venta_id = $conn->insert_id;

                            // Detalle Cancha
                            $stmtDetCancha = $conn->prepare("INSERT INTO inventario_venta_detalles (venta_id, producto_id, cantidad, precio_unitario) VALUES (?, 0, 1, ?)");
                            if ($stmtDetCancha) {
                                $stmtDetCancha->bind_param("id", $venta_id, $precio_cancha);
                                $stmtDetCancha->execute();
                            } else { throw new Exception("Error prepare Detalle Cancha"); }

                            // Detalles Consumos
                            foreach($items_consumo as $item) {
                                $p_id = $item['producto_id'];
                                $qty = $item['cantidad'];
                                $pu = $item['precio_unitario'];

                                $stmtDetItem = $conn->prepare("INSERT INTO inventario_venta_detalles (venta_id, producto_id, cantidad, precio_unitario) VALUES (?, ?, ?, ?)");
                                if ($stmtDetItem) {
                                    $stmtDetItem->bind_param("iiid", $venta_id, $p_id, $qty, $pu);
                                    $stmtDetItem->execute();
                                } else { throw new Exception("Error prepare Detalle Item"); }

                                $conn->query("UPDATE inventario_productos SET stock_actual = stock_actual - $qty WHERE id = $p_id");
                                
                                $motivo = "Consumo Reserva #$id";
                                $stmtMov = $conn->prepare("INSERT INTO inventario_movimientos (club_id, producto_id, tipo, cantidad, motivo, referencia_id) VALUES (?, ?, 'salida', ?, ?, ?)");
                                if ($stmtMov) {
                                    $stmtMov->bind_param("iiisi", $club_id, $p_id, $qty, $motivo, $venta_id);
                                    $stmtMov->execute();
                                }
                            }

                            $conn->query("UPDATE reservas_consumos SET pagado = 1 WHERE reserva_id = $id");
                            $conn->query("UPDATE reservas_cancha SET venta_generada = 1 WHERE id = $id");
                            
                            $conn->commit();
                        } else {
                            $conn->rollback();
                            $integrationError = "SQL Execute Exception: " . $stmtVenta->error;
                        }
                    } else {
                        $conn->rollback();
                        $integrationError = "SQL Prepare Exception: " . $conn->error;
                    }
                }
            }
        } catch (Exception $e) {
            $conn->rollback();
            $integrationError = "Internal Exception: " . $e->getMessage();
        }
    }
    ob_clean();
    echo json_encode([
        "success" => true, 
        "warning" => $integrationError
    ]);
} else {
    ob_clean();
    http_response_code(500);
    echo json_encode(["error" => "Error al actualizar la reserva: " . $conn->error]);
}
?>
