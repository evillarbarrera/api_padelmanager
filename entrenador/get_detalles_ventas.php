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
$mes = $_GET['mes'] ?? null; // format YYYY-MM
$fecha = $_GET['fecha'] ?? null; // format YYYY-MM-DD

if (!$entrenador_id) {
    http_response_code(400);
    echo json_encode(["error" => "entrenador_id es obligatorio"]);
    exit;
}

$query_parts = [];
$params = [];
$types = "i";
$params[] = $entrenador_id;

$sql = "
    SELECT 
        pj.id as sale_id,
        pj.precio_pagado,
        pj.fecha_inicio as fecha,
        pj.metodo_pago,
        p.nombre as pack_nombre,
        p.tipo as pack_tipo,
        u.nombre as alumno_nombre,
        u.foto as alumno_foto
    FROM pack_jugadores pj
    JOIN packs p ON pj.pack_id = p.id
    JOIN usuarios u ON pj.jugador_id = u.id
    WHERE p.entrenador_id = ?
";

if ($fecha) {
    $sql .= " AND DATE(pj.fecha_inicio) = ?";
    $types .= "s";
    $params[] = $fecha;
} elseif ($mes) {
    $sql .= " AND DATE_FORMAT(pj.fecha_inicio, '%Y-%m') = ?";
    $types .= "s";
    $params[] = $mes;
} else {
    // Default to current month
    $sql .= " AND DATE_FORMAT(pj.fecha_inicio, '%Y-%m') = ?";
    $types .= "s";
    $params[] = date('Y-m');
}

$sql .= " ORDER BY pj.fecha_inicio DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$detalles = [];
$total_periodo = 0;

while ($row = $result->fetch_assoc()) {
    $detalles[] = [
        "id" => $row['sale_id'],
        "precio" => (int)$row['precio_pagado'],
        "fecha" => $row['fecha'],
        "metodo_pago" => $row['metodo_pago'] ?? 'Manual',
        "pack_nombre" => $row['pack_nombre'],
        "pack_tipo" => $row['pack_tipo'],
        "alumno_nombre" => $row['alumno_nombre'],
        "alumno_foto" => $row['alumno_foto']
    ];
    $total_periodo += (int)$row['precio_pagado'];
}

echo json_encode([
    "success" => true,
    "total" => $total_periodo,
    "ventas" => $detalles
]);

$conn->close();
