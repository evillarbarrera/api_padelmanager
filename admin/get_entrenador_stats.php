<?php
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
$headers = getallheaders();
require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}

$entrenador_id = $_GET['entrenador_id'] ?? 0;
if (!$entrenador_id) {
    http_response_code(400);
    echo json_encode(["error" => "entrenador_id or coach_id is required"]);
    exit;
}

try {
    // 1. Información General del Entrenador
    $sqlU = "SELECT id, nombre, usuario as email, telefono, categoria, foto_perfil, 
                    created_at, banco_rut, banco_nombre, banco_tipo_cuenta, 
                    banco_numero_cuenta, mp_collector_id, transbank_activo
             FROM usuarios WHERE id = ? AND (rol = 'entrenador' OR rol = 'coach')";
    $stmtU = $conn->prepare($sqlU);
    $stmtU->bind_param("i", $entrenador_id);
    $stmtU->execute();
    $trainerInfo = $stmtU->get_result()->fetch_assoc();

    if (!$trainerInfo) {
        throw new Exception("Entrenador no encontrado");
    }

    $res = [];
    $res['trainer'] = $trainerInfo;

    // 2. Alumnos (Únicos)
    $sqlA = "SELECT COUNT(DISTINCT pj.jugador_id) as total_alumnos 
             FROM pack_jugadores pj
             JOIN packs p ON pj.pack_id = p.id
             WHERE p.entrenador_id = ? AND pj.precio_pagado > 0";
    $stmtA = $conn->prepare($sqlA);
    $stmtA->bind_param("i", $entrenador_id);
    $stmtA->execute();
    $res['alumnos'] = (int)$stmtA->get_result()->fetch_assoc()['total_alumnos'];

    // 3. Packs Creados
    $sqlP = "SELECT COUNT(*) as total, 
                    SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) as activos
             FROM packs WHERE entrenador_id = ?";
    $stmtP = $conn->prepare($sqlP);
    $stmtP->bind_param("i", $entrenador_id);
    $stmtP->execute();
    $packStats = $stmtP->get_result()->fetch_assoc();
    $res['packs'] = [
        'total' => (int)$packStats['total'],
        'activos' => (int)$packStats['activos']
    ];

    // 4. Reservas (Histórico y Pendientes)
    $sqlR = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN estado != 'cancelado' THEN 1 ELSE 0 END) as validas,
                SUM(CASE WHEN estado = 'cancelado' THEN 1 ELSE 0 END) as canceladas,
                SUM(CASE WHEN (fecha < CURDATE() OR (fecha = CURDATE() AND hora_fin <= CURTIME())) AND estado != 'cancelado' THEN 1 ELSE 0 END) as pasadas,
                SUM(CASE WHEN (fecha > CURDATE() OR (fecha = CURDATE() AND hora_inicio > CURTIME())) AND estado != 'cancelado' THEN 1 ELSE 0 END) as futuras
             FROM reservas WHERE entrenador_id = ?";
    $stmtR = $conn->prepare($sqlR);
    $stmtR->bind_param("i", $entrenador_id);
    $stmtR->execute();
    $res['reservas'] = $stmtR->get_result()->fetch_assoc();

    // 5. Ingresos y Cantidad de Packs Vendidos (Soportando PayPal y Comisiones)
    $sqlS = "SELECT 
                COUNT(pj.id) as vendidos, 
                SUM(pj.precio_pagado) as total_ingresos,
                SUM(pj.comision_plataforma) as total_comision,
                SUM(CASE WHEN pj.moneda = 'CLP' THEN pj.precio_pagado ELSE 0 END) as ingresos_clp,
                SUM(CASE WHEN pj.moneda = 'USD' THEN pj.precio_pagado ELSE 0 END) as ingresos_usd
             FROM pack_jugadores pj
             JOIN packs p ON pj.pack_id = p.id
             WHERE p.entrenador_id = ? AND pj.precio_pagado > 0";
    $stmtS = $conn->prepare($sqlS);
    $stmtS->bind_param("i", $entrenador_id);
    $stmtS->execute();
    $saleStats = $stmtS->get_result()->fetch_assoc();
    $res['ventas'] = [
        'packs_vendidos' => (int)$saleStats['vendidos'],
        'ingresos_totales' => (float)$saleStats['total_ingresos'],
        'total_comision' => (float)$saleStats['total_comision'],
        'ingresos_clp' => (float)$saleStats['ingresos_clp'],
        'ingresos_usd' => (float)$saleStats['ingresos_usd']
    ];

    // 6. Estado Conectividad
    $res['conectividad'] = [
        'mercadopago' => !empty($trainerInfo['mp_collector_id']),
        'banco' => !empty($trainerInfo['banco_rut']),
        'transbank' => (bool)$trainerInfo['transbank_activo']
    ];

    echo json_encode(["success" => true, "data" => $res]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}

$conn->close();
?>
