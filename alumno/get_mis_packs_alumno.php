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

$headers = getallheaders();
require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}

$jugador_id = $_GET['jugador_id'] ?? null;
$entrenador_id = $_GET['entrenador_id'] ?? null;

if (!$jugador_id) {
    http_response_code(400);
    echo json_encode(["error" => "jugador_id requerido"]);
    exit;
}

require_once "../db.php";

// 1. Obtener la lista base de los packs comprados (sin subconsultas de conteo que causen agregación masiva)
$sql = "
    SELECT 
        pj.id as pack_jugador_id,
        pj.sesiones_usadas as sesiones_usadas_manual,
        pj.fecha_inicio,
        pj.fecha_fin,
        pk.id as pack_id,
        pk.nombre as pack_nombre,
        pk.sesiones_totales,
        pk.tipo,
        pk.cantidad_personas,
        pk.rango_horario_inicio,
        pk.rango_horario_fin,
        pk.entrenador_id,
        pj.precio_pagado,
        pj.estado_pago,
        COALESCE(ig.estado, 'activo') as estado_inscripcion
    FROM pack_jugadores pj
    JOIN packs pk ON pj.pack_id = pk.id
    LEFT JOIN inscripciones_grupales ig ON ig.pack_id = pk.id AND ig.jugador_id = pj.jugador_id
    WHERE (
        -- Condición 1: Compras propias o pases de invitado oficial (Muestra compras múltiples del mismo pack correctamente)
        pj.jugador_id = ? 
        OR pj.id IN (SELECT pack_jugadores_id FROM pack_jugadores_adicionales WHERE jugador_id = ? AND estado = 'aceptado')
        
        -- Condición 2: Asistencia de cortesía (Si está en una reserva de un pack ajeno, le mostramos solo 1 tarjeta representativa)
        OR (
            pj.pack_id IN (SELECT r_sub.pack_id FROM reserva_jugadores rj_sub JOIN reservas r_sub ON r_sub.id = rj_sub.reserva_id WHERE rj_sub.jugador_id = ? AND r_sub.pack_id > 0 AND r_sub.estado != 'cancelado')
            AND pj.id = (SELECT MIN(id) FROM pack_jugadores WHERE pack_id = pk.id)
            AND pk.id NOT IN (
                SELECT pj_own.pack_id FROM pack_jugadores pj_own 
                WHERE pj_own.jugador_id = ? 
                OR pj_own.id IN (SELECT pack_jugadores_id FROM pack_jugadores_adicionales WHERE jugador_id = ? AND estado = 'aceptado')
            )
        )
    )
    AND pk.tipo != 'grupal'
";

if ($entrenador_id) {
    $sql .= " AND pk.entrenador_id = ?";
}

$sql .= " GROUP BY pj.id ORDER BY pj.fecha_inicio ASC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(["error" => "Prepare failed: " . $conn->error]);
    exit;
}

if ($entrenador_id) {
    $stmt->bind_param("iiiiii", $jugador_id, $jugador_id, $jugador_id, $jugador_id, $jugador_id, $entrenador_id);
} else {
    $stmt->bind_param("iiiii", $jugador_id, $jugador_id, $jugador_id, $jugador_id, $jugador_id);
}

$stmt->execute();
$res = $stmt->get_result();

$all_packs = [];
while ($row = $res->fetch_assoc()) {
    $all_packs[] = $row;
}

// 2. Obtener el total REAL de sesiones reservadas por el jugador para cada TIPO DE NOMBRE (bag global por nombre-tipo)
// Solo contamos reservas asociadas a los packs del entrenador si se especificó entrenador_id
$sqlTotal = "
    SELECT 
        p.nombre, 
        COUNT(*) as total_reservas,
        SUM(CASE WHEN (r.fecha < CURDATE() OR (r.fecha = CURDATE() AND r.hora_fin <= CURTIME())) THEN 1 ELSE 0 END) as total_pasadas
    FROM reserva_jugadores rj
    JOIN reservas r ON rj.reserva_id = r.id
    JOIN packs p ON r.pack_id = p.id
    WHERE rj.jugador_id = ? 
      AND r.estado != 'cancelado'
";

if ($entrenador_id) {
    $sqlTotal .= " AND p.entrenador_id = ?";
}

$sqlTotal .= " GROUP BY p.nombre";

$stmtT = $conn->prepare($sqlTotal);
if ($entrenador_id) {
    $stmtT->bind_param("ii", $jugador_id, $entrenador_id);
} else {
    $stmtT->bind_param("i", $jugador_id);
}
$stmtT->execute();
$resT = $stmtT->get_result();

$totals_map = [];
$past_map = [];
while ($t = $resT->fetch_assoc()) {
    $totals_map[$t['nombre']] = (int)$t['total_reservas'];
    $past_map[$t['nombre']] = (int)$t['total_pasadas'];
}

// 3. Distribución FIFO (First In, First Out)
// Vamos recorriendo los packs (del más viejo al más nuevo) y gastando las reservas globales.
$results = [];
foreach ($all_packs as $pack) {
    $pName = trim($pack['pack_nombre']); // Usar el nombre como bolsa global
    $maxCapacity = (int)($pack['sesiones_totales'] ?? 0);

    // Obtener lo que queda en la bolsa para este nombre de pack
    $globalRemaining = isset($totals_map[$pName]) ? $totals_map[$pName] : 0;
    $globalPastRemaining = isset($past_map[$pName]) ? $past_map[$pName] : 0;

    $assigned_reservadas = min($maxCapacity, $globalRemaining);
    $assigned_pasadas = min($assigned_reservadas, $globalPastRemaining);

    $pack['sesiones_reservadas'] = $assigned_reservadas;
    $pack['sesiones_pasadas'] = $assigned_pasadas;

    // IMPORTANTE: Restar de la bolsa global PARA EL SIGUIENTE PACK
    if (isset($totals_map[$pName])) {
        $totals_map[$pName] = $totals_map[$pName] - $assigned_reservadas;
    }
    if (isset($past_map[$pName])) {
        $past_map[$pName] = $past_map[$pName] - $assigned_pasadas;
    }

    $results[] = $pack;
}

// 4. Volver a ordenar por fecha DESC para la vista final si se prefiere así
usort($results, function ($a, $b) {
    return strtotime($b['fecha_inicio']) - strtotime($a['fecha_inicio']);
});

// Agregar invitados (lógica original)
foreach ($results as &$row) {
    $invitados = [];
    if (($row['cantidad_personas'] ?? 1) > 1) {
        $sqlInv = "
            SELECT u.id, u.nombre, u.usuario, pja.estado, pja.fecha_asignacion 
            FROM pack_jugadores_adicionales pja
            JOIN usuarios u ON pja.jugador_id = u.id
            WHERE pja.pack_jugadores_id = ?
        ";
        $stmtInv = $conn->prepare($sqlInv);
        if ($stmtInv) {
            $stmtInv->bind_param("i", $row['pack_jugador_id']);
            $stmtInv->execute();
            $resInv = $stmtInv->get_result();
            while ($inv = $resInv->fetch_assoc()) {
                $invitados[] = $inv;
            }
        }
    }
    $row['invitados'] = $invitados;
}

echo json_encode(["success" => true, "data" => $results]);
