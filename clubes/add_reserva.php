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
function ensureColumnReservas($conn, $column, $definition)
{
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

$cancha_id = $data['cancha_id'] ?? 0;
$fecha = $data['fecha'] ?? '';
$hora_inicio = $data['hora_inicio'] ?? '';
$duracion = $data['duracion'] ?? 90;
$metodo_pago = strtolower(trim($data['metodo_pago'] ?? 'total'));
$pagos = $data['pagos'] ?? [];

// Leer pagos (prioridad a los campos aplanados)
$pago_p1 = isset($data['pago_p1']) ? intval($data['pago_p1']) : (isset($pagos['p1']) && $pagos['p1'] ? 1 : 0);
$pago_p2 = isset($data['pago_p2']) ? intval($data['pago_p2']) : (isset($pagos['p2']) && $pagos['p2'] ? 1 : 0);
$pago_p3 = isset($data['pago_p3']) ? intval($data['pago_p3']) : (isset($pagos['p3']) && $pagos['p3'] ? 1 : 0);
$pago_p4 = isset($data['pago_p4']) ? intval($data['pago_p4']) : (isset($pagos['p4']) && $pagos['p4'] ? 1 : 0);

// Extraer jugadores (usar null si es 0 para evitar fallos de Foreign Key)
$jugador_id = !empty($data['jugador_id']) ? intval($data['jugador_id']) : null;
$jugador2_id = !empty($data['jugador2_id']) ? intval($data['jugador2_id']) : null;
$jugador3_id = !empty($data['jugador3_id']) ? intval($data['jugador3_id']) : null;
$jugador4_id = !empty($data['jugador4_id']) ? intval($data['jugador4_id']) : null;

$nombre_externo = $data['nombre_externo'] ?? '';
$nombre_externo2 = $data['nombre_externo2'] ?? '';
$nombre_externo3 = $data['nombre_externo3'] ?? '';
$nombre_externo4 = $data['nombre_externo4'] ?? '';

if (!$cancha_id || !$fecha || !$hora_inicio) {
    ob_clean();
    http_response_code(400);
    echo json_encode(["error" => "Faltan datos obligatorios"]);
    exit;
}

// Calcular hora_fin
$time = strtotime($hora_inicio);
$hora_fin = date("H:i:s", $time + (($data['duracion'] ?? 90) * 60));

// 1. Validar disponibilidad
$sqlCheck = "SELECT id FROM reservas_cancha 
             WHERE cancha_id = ? AND fecha = ? 
             AND ((hora_inicio < ? AND hora_fin > ?) OR (hora_inicio < ? AND hora_fin > ?))
             AND estado != 'Cancelada'";
$stmtCheck = $conn->prepare($sqlCheck);
$stmtCheck->bind_param("isssss", $cancha_id, $fecha, $hora_fin, $hora_inicio, $hora_inicio, $hora_inicio);
$stmtCheck->execute();
if ($stmtCheck->get_result()->num_rows > 0) {
    ob_clean();
    http_response_code(409);
    echo json_encode(["error" => "La cancha ya está reservada en ese horario"]);
    exit;
}

$pagado = intval($data['pagado'] ?? 0);
if ($metodo_pago === 'proporcional') {
    $allPaid = ($pago_p1 == 1);
    if (!empty($nombre_externo2) || !empty($jugador2_id)) $allPaid = $allPaid && ($pago_p2 == 1);
    if (!empty($nombre_externo3) || !empty($jugador3_id)) $allPaid = $allPaid && ($pago_p3 == 1);
    if (!empty($nombre_externo4) || !empty($jugador4_id)) $allPaid = $allPaid && ($pago_p4 == 1);
    $pagado = $allPaid ? 1 : 0;
}

$estado = $data['estado'] ?? 'Confirmada';
$sqlInsert = "INSERT INTO reservas_cancha 
              (cancha_id, usuario_id, jugador2_id, jugador3_id, jugador4_id, 
               nombre_externo, nombre_externo2, nombre_externo3, nombre_externo4, 
               fecha, hora_inicio, hora_fin, precio, pagado, estado, metodo_pago, pago_p1, pago_p2, pago_p3, pago_p4) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($sqlInsert);
$stmt->bind_param(
    "iiiiisssssssdissiiii",
    $cancha_id,
    $jugador_id,
    $jugador2_id,
    $jugador3_id,
    $jugador4_id,
    $nombre_externo,
    $nombre_externo2,
    $nombre_externo3,
    $nombre_externo4,
    $fecha,
    $hora_inicio,
    $hora_fin,
    $data['precio'],
    $pagado,
    $estado,
    $metodo_pago,
    $pago_p1,
    $pago_p2,
    $pago_p3,
    $pago_p4
);

if ($stmt->execute()) {
    ob_clean();
    echo json_encode(["success" => true, "id" => $conn->insert_id]);
} else {
    ob_clean();
    http_response_code(500);
    echo json_encode(["error" => "Error al guardar la reserva: " . $conn->error]);
}
