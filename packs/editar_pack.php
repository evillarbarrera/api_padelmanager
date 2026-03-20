<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

// Responder OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Obtener header Authorization
$headers = getallheaders();
require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}



// Obtener datos del body
$data = json_decode(file_get_contents("php://input"), true);
error_log("Updating pack: " . json_encode($data));

$entrenador_id = $data['entrenador_id'] ?? 0; // ID recibido desde Angular

if (!$data || !isset($data['id'])) {
    http_response_code(400);
    echo json_encode(["error" => "Datos inválidos"]);
    exit;
}

require_once "../db.php";

// Actualizar pack
$sql = "
  UPDATE packs SET 
    nombre = ?, 
    descripcion = ?, 
    tipo = ?, 
    sesiones_totales = ?, 
    duracion_sesion_min = ?, 
    precio = ?,
    cantidad_personas = ?,
    rango_horario_inicio = ?,
    rango_horario_fin = ?,
    capacidad_minima = ?,
    capacidad_maxima = ?,
    dia_semana = ?,
    hora_inicio = ?,
    categoria = ?
  WHERE id = ? AND entrenador_id = ?
";

// If empty/null, pass DB null
$r_inicio = !empty($data['rango_horario_inicio']) ? $data['rango_horario_inicio'] : null;
$r_fin = !empty($data['rango_horario_fin']) ? $data['rango_horario_fin'] : null;
$cant_pers = !empty($data['cantidad_personas']) ? $data['cantidad_personas'] : 1;

$cap_min = $data['capacidad_minima'] ?? null;
$cap_max = $data['capacidad_maxima'] ?? null;
$dia = isset($data['dia_semana']) ? $data['dia_semana'] : null; // dia_semana can be 0 (Lunes/Domingo)
$h_inicio = $data['hora_inicio'] ?? null;
$cat = $data['categoria'] ?? null;

$stmt = $conn->prepare($sql);
$stmt->bind_param(
    "sssiiiissiiissii", // 16 total: nombre(s), desc(s), tipo(s), sesiones(i), duracion(i), precio(i), cant_pers(i), r_inicio(s), r_fin(s), cap_min(i), cap_max(i), dia(i), h_ini(s), cat(s), id(i), entrenador_id(i)
    $data['nombre'],      // 1 (s)
    $data['descripcion'], // 2 (s)
    $data['tipo'],        // 3 (s)
    $data['sesiones_totales'],    // 4 (i)
    $data['duracion_sesion_min'], // 5 (i)
    $data['precio'],              // 6 (i)
    $cant_pers,                   // 7 (i)
    $r_inicio,                    // 8 (s)
    $r_fin,                       // 9 (s)
    $cap_min,              // 10 (i)
    $cap_max,              // 11 (i)
    $dia,                  // 12 (i)
    $h_inicio,             // 13 (s)
    $cat,                  // 14 (s)
    $data['id'],           // 15 (i)
    $entrenador_id         // 16 (i)
);

if ($stmt->execute()) {
    echo json_encode(["success" => true]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al actualizar pack"]);
}
