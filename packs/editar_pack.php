<?php
header("Access-Control-Allow-Origin: http://localhost:8100");
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
$auth = $headers['Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

// Validar token
if ($auth !== 'Bearer ' . base64_encode("1|padel_academy")) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}



// Obtener datos del body
$data = json_decode(file_get_contents("php://input"), true);
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
    "sssiidissiiissii",
    $data['nombre'],
    $data['descripcion'],
    $data['tipo'],
    $data['sesiones_totales'],
    $data['duracion_sesion_min'],
    $data['precio'],
    $cant_pers,
    $r_inicio,
    $r_fin,
    $cap_min,
    $cap_max,
    $dia,
    $h_inicio,
    $cat,
    $data['id'],
    $entrenador_id
);

if ($stmt->execute()) {
    echo json_encode(["success" => true]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al actualizar pack"]);
}
