<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
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

require_once "../db.php";

$entrenador_id = $_GET['entrenador_id'] ?? null;

if (!$entrenador_id) {
    http_response_code(400);
    echo json_encode(["error" => "entrenador_id es obligatorio"]);
    exit;
}

$first_day = date('Y-m-01 00:00:00');
$last_day = date('Y-m-t 23:59:59');

// 1. RECAUDADO POR DIA (Ventas reales de packs este mes)
$sql_packs = "
    SELECT DATE(pj.fecha_inicio) as fecha, SUM(pj.precio_pagado) as total_dia
    FROM pack_jugadores pj
    JOIN packs p ON pj.pack_id = p.id
    WHERE p.entrenador_id = ? 
      AND pj.fecha_inicio BETWEEN ? AND ?
    GROUP BY DATE(pj.fecha_inicio)
    ORDER BY fecha ASC
";
$stmt = $conn->prepare($sql_packs);
$stmt->bind_param("iss", $entrenador_id, $first_day, $last_day);
$stmt->execute();
$res = $stmt->get_result();

$ventas_por_dia = [];
$total_recaudado = 0;

while ($row = $res->fetch_assoc()) {
    $ventas_por_dia[] = [
        "fecha" => $row['fecha'],
        "total" => (float)$row['total_dia']
    ];
    $total_recaudado += (float)$row['total_dia'];
}

// Fallback legacy no longer completely needed, but we keep the structure just in case
$recaudado_sin_fecha = 0;
$total_recaudado += $recaudado_sin_fecha;

// Mes a Mes (Año Completo actual)
$first_day_year = date('Y-01-01 00:00:00');
$last_day_year = date('Y-12-31 23:59:59');

$sql_meses = "
    SELECT DATE_FORMAT(pj.fecha_inicio, '%Y-%m') as mes, SUM(pj.precio_pagado) as total_mes
    FROM pack_jugadores pj
    JOIN packs p ON pj.pack_id = p.id
    WHERE p.entrenador_id = ? 
      AND pj.fecha_inicio BETWEEN ? AND ?
    GROUP BY DATE_FORMAT(pj.fecha_inicio, '%Y-%m')
    ORDER BY mes ASC
";
$stmt = $conn->prepare($sql_meses);
$stmt->bind_param("iss", $entrenador_id, $first_day_year, $last_day_year);
$stmt->execute();
$res_meses = $stmt->get_result();

$ventas_por_mes = [];
$total_recaudado_anio = 0;
while ($row = $res_meses->fetch_assoc()) {
    $ventas_por_mes[] = [
        "mes" => $row['mes'],
        "total" => (float)$row['total_mes']
    ];
    $total_recaudado_anio += (float)$row['total_mes'];
}

// 2. PROYECTADO 
// La meta proyectada es el valor total de los packs que están siendo usados en la agenda este mes
$sql_proyectado = "
    SELECT SUM(DISTINCT p.precio) as total_proy
    FROM reservas r
    JOIN packs p ON r.pack_id = p.id
    WHERE r.entrenador_id = ? 
      AND r.estado != 'cancelado'
      AND r.fecha BETWEEN ? AND ?
";
$stmt = $conn->prepare($sql_proyectado);
$stmt->bind_param("iss", $entrenador_id, $first_day, $last_day);
$stmt->execute();
$total_proy_packs = (float)$stmt->get_result()->fetch_assoc()['total_proy'];

$total_proyectado = max($total_recaudado, $total_proy_packs);

// Devolvemos los datos
echo json_encode([
    "recaudado" => (int)$total_recaudado,
    "recaudado_anio" => (int)$total_recaudado_anio,
    "proyectado" => (int)$total_recaudado, // Ya no se usa tanto proyectado base si tenemos datos exactos
    "moneda" => "CLP",
    "periodo" => date('F Y'),
    "ventas_por_dia" => $ventas_por_dia,
    "ventas_por_mes" => $ventas_por_mes
]);

$conn->close();
