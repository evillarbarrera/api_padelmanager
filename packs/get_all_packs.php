<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

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


require_once "../db.php";

// Parameters for geolocation
$myLat = isset($_GET['lat']) ? floatval($_GET['lat']) : null;
$myLng = isset($_GET['lng']) ? floatval($_GET['lng']) : null;
$radius = isset($_GET['radius']) ? floatval($_GET['radius']) : null;
$entrenador_id = isset($_GET['entrenador_id']) ? intval($_GET['entrenador_id']) : null;
$region = isset($_GET['region']) ? $_GET['region'] : null;
$comuna = isset($_GET['comuna']) ? $_GET['comuna'] : null;

// Base SQL
$sql = "
  SELECT p.*, 
         e.nombre as entrenador_nombre,
         e.foto_perfil as entrenador_foto,
         e.transbank_active,
         (SELECT descripcion FROM usuarios WHERE id = e.id) as entrenador_descripcion,
         (SELECT COUNT(*) FROM inscripciones_grupales ig2 WHERE ig2.pack_id = p.id AND ig2.estado = 'activo') as cupos_ocupados,
         (p.capacidad_maxima - (SELECT COUNT(*) FROM inscripciones_grupales ig3 WHERE ig3.pack_id = p.id AND ig3.estado = 'activo')) as cupos_disponibles,
         d.latitud as trainer_lat,
         d.longitud as trainer_lng,
         d.comuna as trainer_comuna,
         d.region as trainer_region
";

// Haversine calculation only if coordinates provided
if ($myLat && $myLng) {
    $sql .= ", ( 6371 * acos( cos( radians($myLat) ) * cos( radians( d.latitud ) ) * cos( radians( d.longitud ) - radians($myLng) ) + sin( radians($myLat) ) * sin( radians( d.latitud ) ) ) ) AS distancia ";
} else {
    $sql .= ", NULL as distancia ";
}

$sql .= "
  FROM packs p
  INNER JOIN usuarios e ON e.id = p.entrenador_id
  LEFT JOIN direcciones d ON d.usuario_id = e.id
  WHERE p.activo = 1 AND e.rol = 'entrenador'
";

if ($entrenador_id) {
    $sql .= " AND p.entrenador_id = $entrenador_id ";
}

if ($region) {
    $safeRegion = $conn->real_escape_string($region);
    $sql .= " AND d.region = '$safeRegion' ";
}

if ($comuna) {
    $safeComuna = $conn->real_escape_string($comuna);
    $sql .= " AND d.comuna = '$safeComuna' ";
}

// Filter by radius if location and radius provided
if ($myLat && $myLng && $radius) {
    $sql .= " HAVING distancia < $radius ";
}

$sql .= " ORDER BY p.created_at DESC";

$result = $conn->query($sql);

$data = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Round distance for UI
        if ($row['distancia'] !== null) {
            $row['distancia'] = round(floatval($row['distancia']), 2);
        }
        $data[] = $row;
    }
} else {
    // If error (e.g. unknown column latitud yet), return empty or error
    // For now silent fallback
}

echo json_encode($data);
