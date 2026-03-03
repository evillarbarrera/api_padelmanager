<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$headers = getallheaders();
$auth = $headers['Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

if ($auth !== 'Bearer ' . base64_encode("1|padel_academy")) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
  http_response_code(400);
  echo json_encode(["error" => "Datos inválidos"]);
  exit;
}

$entrenador_id = $data['entrenador_id'] ?? 0;
require_once "../db.php";

$tipo = $data['tipo'] ?? 'individual';

// Si es individual, ponemos valores por defecto para los nuevos campos
if ($tipo === 'individual') {
  $capacidad_minima = 1;
  $capacidad_maxima = 1;
  $dia_semana = null;
  $hora_inicio = null;
  $categoria = null;
} else if ($tipo === 'grupal') {
  // Validar que los campos obligatorios para packs grupales estén presentes
  $capacidad_minima = $data['capacidad_minima'] ?? null;
  $capacidad_maxima = $data['capacidad_maxima'] ?? null;
  $dia_semana = $data['dia_semana'] ?? null;
  $hora_inicio = $data['hora_inicio'] ?? null;
  $categoria = $data['categoria'] ?? null;

  // Validaciones
  if (!$capacidad_minima || !$capacidad_maxima || ($dia_semana === null) || !$hora_inicio || !$categoria) {
    http_response_code(400);
    echo json_encode(["error" => "Para packs grupales son obligatorios: capacidad_minima, capacidad_maxima, dia_semana, hora_inicio, categoria"]);
    exit;
  }

  if ($capacidad_minima < 2 || $capacidad_minima > 6) {
    http_response_code(400);
    echo json_encode(["error" => "Capacidad mínima debe estar entre 2 y 6"]);
    exit;
  }

  if ($capacidad_maxima < $capacidad_minima || $capacidad_maxima > 6) {
    http_response_code(400);
    echo json_encode(["error" => "Capacidad máxima debe estar entre capacidad_minima y 6"]);
    exit;
  }

  if ($dia_semana < 0 || $dia_semana > 6) {
    http_response_code(400);
    echo json_encode(["error" => "Día de semana debe estar entre 0 (domingo) y 6 (sábado)"]);
    exit;
  }
} else {
  http_response_code(400);
  echo json_encode(["error" => "Tipo de pack no válido. Debe ser 'individual' o 'grupal'"]);
  exit;
}


$rango_horario_inicio = $data['rango_horario_inicio'] ?? null;
$rango_horario_fin    = $data['rango_horario_fin'] ?? null;

// Validate time range if provided
if (($rango_horario_inicio && !$rango_horario_fin) || (!$rango_horario_inicio && $rango_horario_fin)) {
    http_response_code(400);
    echo json_encode(["error" => "Si define un rango horario, debe incluir inicio y fin"]);
    exit;
}

$cantidad_personas = $data['cantidad_personas'] ?? 1;

$sql = "
  INSERT INTO packs
  (entrenador_id, nombre, descripcion, tipo, sesiones_totales, duracion_sesion_min, precio, capacidad_minima, capacidad_maxima, dia_semana, hora_inicio, rango_horario_inicio, rango_horario_fin, categoria, cantidad_personas, activo)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
";

// Debug log for incoming data
error_log("Creating pack with: " . json_encode($data));

$stmt = $conn->prepare($sql);
$stmt->bind_param(
  "isssiiiiiiisssi", // 15 total: i s s s i i i i i i s s s s i
  $entrenador_id,
  $data['nombre'],
  $data['descripcion'],
  $data['tipo'],
  $data['sesiones_totales'],
  $data['duracion_sesion_min'],
  $data['precio'],
  $capacidad_minima,
  $capacidad_maxima,
  $dia_semana,
  $hora_inicio,
  $rango_horario_inicio,
  $rango_horario_fin,
  $categoria,
  $cantidad_personas
);

if ($stmt->execute()) {
  echo json_encode(["success" => true, "id" => $conn->insert_id]);
} else {
  http_response_code(500);
  echo json_encode(["error" => "Error al crear pack: " . $conn->error]);
}
