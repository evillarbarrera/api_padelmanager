<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
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

require_once "../db.php";

$entrenador_id = $_GET['entrenador_id'] ?? null;

if (!$entrenador_id) {
    http_response_code(400);
    echo json_encode(["error" => "entrenador_id es obligatorio"]);
    exit;
}

$data = [
    "total_alumnos" => 0,
    "clases_mes" => 0,
    "clases_grupales_mes" => 0,
    "clases_hoy" => 0
];

// 1. Cantidad de alumnos total (únicos con packs activos o reservas activas)
$sql_alumnos = "
SELECT COUNT(DISTINCT jugador_id) as total FROM (
    SELECT pj.jugador_id 
    FROM pack_jugadores pj
    JOIN packs p ON p.id = pj.pack_id
    WHERE p.entrenador_id = ? AND p.activo = 1
    
    UNION
    
    SELECT rj.jugador_id
    FROM reserva_jugadores rj
    JOIN reservas r ON r.id = rj.reserva_id
    WHERE r.entrenador_id = ? AND r.estado = 'reservado'
) as alumnos_unicos
";

$stmt = $conn->prepare($sql_alumnos);
$stmt->bind_param("ii", $entrenador_id, $entrenador_id);
$stmt->execute();
$data["total_alumnos"] = (int)$stmt->get_result()->fetch_assoc()['total'];

// 2. Clases del mes (Asegurando contar por sesión única, no por alumno)
$first_day = date('Y-m-01');
$last_day = date('Y-m-t');

$sql_reservas = "
SELECT fecha, hora_inicio, tipo, pack_id
FROM reservas
WHERE entrenador_id = ? 
  AND estado != 'cancelado'
  AND fecha BETWEEN ? AND ?
GROUP BY fecha, hora_inicio
";
$stmt = $conn->prepare($sql_reservas);
$stmt->bind_param("iss", $entrenador_id, $first_day, $last_day);
$stmt->execute();
$res_reservas = $stmt->get_result();

$clases_unicas = [];
$grupales_unicas = 0;

while($r = $res_reservas->fetch_assoc()) {
    $key = $r['fecha'] . '_' . $r['hora_inicio'];
    $clases_unicas[$key] = true;
    if ($r['tipo'] === 'grupal' || $r['tipo'] === 'pack_grupal') {
        $grupales_unicas++;
    }
}

$data["clases_mes"] = count($clases_unicas);
$data["clases_grupales_mes"] = $grupales_unicas;

// 3. Clases de hoy (Sesiones únicas)
$hoy = date('Y-m-d');
$sql_hoy = "
SELECT COUNT(DISTINCT hora_inicio) as total
FROM reservas
WHERE entrenador_id = ? 
  AND estado != 'cancelado'
  AND fecha = ?
";
$stmt = $conn->prepare($sql_hoy);
$stmt->bind_param("is", $entrenador_id, $hoy);
$stmt->execute();
$res_hoy_res = $stmt->get_result()->fetch_assoc();
$total_hoy = (int)$res_hoy_res['total'];
$data["clases_hoy"] = $total_hoy;

echo json_encode($data);
$conn->close();
