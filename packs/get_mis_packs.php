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
error_log("Auth header: " . $auth);

// Validar token
if ($auth !== 'Bearer ' . base64_encode("1|padel_academy")) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

// Entrenador logueado simulado
// Recuperar entrenador_id desde la URL
$entrenador_id = isset($_GET['entrenador_id']) ? intval($_GET['entrenador_id']) : 0;

if ($entrenador_id <= 0) {
    http_response_code(400);
    echo json_encode(["error" => "ID de entrenador inválido"]);
    exit;
}

require_once "../db.php";

$sql = "
  SELECT 
    id,
    nombre,
    descripcion,
    tipo,
    sesiones_totales,
    duracion_sesion_min,
    precio,
    activo,
    created_at,
    cantidad_personas,
    rango_horario_inicio,
    rango_horario_fin,
    capacidad_minima,
    capacidad_maxima,
    dia_semana,
    hora_inicio,
    categoria
  FROM packs
  WHERE entrenador_id = ?
  and activo = 1
  ORDER BY created_at DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $entrenador_id);
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode($data);
