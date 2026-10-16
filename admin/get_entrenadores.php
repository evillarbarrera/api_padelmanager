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

// Get all trainers with their bank and config data + quick stats
$sql = "SELECT u.id, u.nombre, u.usuario, u.foto, u.foto_perfil, u.telefono, u.categoria, 
        u.banco_titular, u.banco_rut, u.banco_nombre, u.banco_tipo_cuenta, u.banco_numero_cuenta,
        u.transbank_activo, u.comision_activa, u.comision_porcentaje, u.mp_collector_id, u.plan_id,
        (SELECT COUNT(DISTINCT pj.jugador_id) FROM pack_jugadores pj JOIN packs p ON pj.pack_id = p.id WHERE p.entrenador_id = u.id AND pj.precio_pagado > 0) as total_alumnos,
        (SELECT COUNT(*) FROM pack_jugadores pj JOIN packs p ON pj.pack_id = p.id WHERE p.entrenador_id = u.id AND pj.precio_pagado > 0) as packs_vendidos,
        (SELECT SUM(pj.comision_plataforma) FROM pack_jugadores pj JOIN packs p ON pj.pack_id = p.id WHERE p.entrenador_id = u.id AND pj.precio_pagado > 0) as ganancia_plataforma
        FROM usuarios u WHERE u.rol = 'entrenador' ORDER BY u.nombre ASC";


$result = $conn->query($sql);
$entrenadores = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $row['total_alumnos'] = (int)$row['total_alumnos'];
        $row['packs_vendidos'] = (int)$row['packs_vendidos'];
        $entrenadores[] = $row;
    }
}

echo json_encode(["success" => true, "data" => $entrenadores]);
