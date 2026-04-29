<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
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
  $fecha = $data['fecha'] ?? null;
  $hora_inicio = $data['hora_inicio'] ?? null;
  $categoria = $data['categoria'] ?? null;

  // Validaciones
  if (!$capacidad_minima || !$capacidad_maxima || ($dia_semana === null && !$fecha) || !$hora_inicio || !$categoria) {
    http_response_code(400);
    echo json_encode(["error" => "Para packs grupales son obligatorios: capacidad_minima, capacidad_maxima, (dia_semana o fecha), hora_inicio, categoria"]);
    exit;
  }

  if ($capacidad_minima < 1 || $capacidad_minima > 10) {
    http_response_code(400);
    echo json_encode(["error" => "Capacidad mínima inválida"]);
    exit;
  }

  if ($capacidad_maxima < $capacidad_minima) {
    http_response_code(400);
    echo json_encode(["error" => "Capacidad máxima debe ser mayor o igual a capacidad_minima"]);
    exit;
  }
} else {
  http_response_code(400);
  echo json_encode(["error" => "Tipo de pack no válido. Debe ser 'individual' o 'grupal'"]);
  exit;
}


$rango_horario_inicio = $data['rango_horario_inicio'] ?? null;
$rango_horario_fin    = $data['rango_horario_fin'] ?? null;
$permite_inscripcion  = $data['permite_inscripcion'] ?? 1;

// Validate time range if provided
if (($rango_horario_inicio && !$rango_horario_fin) || (!$rango_horario_inicio && $rango_horario_fin)) {
    http_response_code(400);
    echo json_encode(["error" => "Si define un rango horario, debe incluir inicio y fin"]);
    exit;
}

$cantidad_personas = $data['cantidad_personas'] ?? 1;

$sql = "
  INSERT INTO packs
  (entrenador_id, nombre, descripcion, tipo, sesiones_totales, duracion_sesion_min, precio, capacidad_minima, capacidad_maxima, dia_semana, fecha, hora_inicio, rango_horario_inicio, rango_horario_fin, categoria, cantidad_personas, permite_inscripcion, activo)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
";

// Debug log for incoming data
error_log("Creating pack with: " . json_encode($data));

$stmt = $conn->prepare($sql);
$stmt->bind_param(
  "isssiiiiiisssssii", 
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
  $fecha,
  $hora_inicio,
  $rango_horario_inicio,
  $rango_horario_fin,
  $categoria,
  $cantidad_personas,
  $permite_inscripcion
);

if ($stmt->execute()) {
  echo json_encode(["success" => true, "id" => $conn->insert_id]);
} else {
  http_response_code(500);
  echo json_encode(["error" => "Error al crear pack: " . $conn->error]);
}
