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

// Obtener header Authorization
$headers = getallheaders();
require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}


require_once "../db.php";

// Parameters for geolocation
$myLat = isset($_GET['lat']) ? floatval($_GET['lat']) : null;
$myLng = isset($_GET['lng']) ? floatval($_GET['lng']) : null;
$radius = isset($_GET['radius']) ? floatval($_GET['radius']) : null;
$entrenador_id = isset($_GET['entrenador_id']) ? intval($_GET['entrenador_id']) : null;
$region = isset($_GET['region']) ? $_GET['region'] : null;
$comuna = isset($_GET['comuna']) ? $_GET['comuna'] : null;

// Base SQL with Optimized Joins for counts
$sql = "
  SELECT p.*, 
         e.nombre as entrenador_nombre,
         e.foto_perfil as entrenador_foto,
         e.descripcion as entrenador_descripcion,
         COALESCE(ig_counts.cupos_ocupados, 0) as cupos_ocupados,
         (p.capacidad_maxima - COALESCE(ig_counts.cupos_ocupados, 0)) as cupos_disponibles,
         cl.nombre as club_nombre,
         NULL as trainer_lat,
         NULL as trainer_lng,
         d_user.comuna as trainer_comuna,
         d_user.region as trainer_region
";

// ... [Haversine logic stays the same] ...
// Geolocation logic removed as columns don't exist
$sql .= ", NULL as distancia ";

$sql .= "
  FROM packs p
  INNER JOIN usuarios e ON e.id = p.entrenador_id
  LEFT JOIN clubes cl ON cl.id = p.club_id
  LEFT JOIN direcciones d_club ON d_club.club_id = p.club_id
  LEFT JOIN direcciones d_user ON d_user.usuario_id = p.entrenador_id
  LEFT JOIN (
      SELECT pack_id, COUNT(*) as cupos_ocupados 
      FROM inscripciones_grupales 
      WHERE estado = 'activo' 
      GROUP BY pack_id
  ) ig_counts ON ig_counts.pack_id = p.id
  WHERE p.activo = 1 AND e.rol = 'entrenador'
    AND (p.fecha IS NULL OR p.fecha >= CURDATE())
    AND (p.tipo != 'grupal' OR p.permite_inscripcion = 1)
";

if ($entrenador_id) {
    $sql .= " AND p.entrenador_id = $entrenador_id ";
}

if ($region) {
    $safeRegion = $conn->real_escape_string($region);
    $sql .= " AND d_user.region = '$safeRegion' ";
}

if ($comuna) {
    $safeComuna = $conn->real_escape_string($comuna);
    $sql .= " AND d_user.comuna = '$safeComuna' ";
}

// Filter by radius if location and radius provided
if ($myLat && $myLng && $radius) {
    $sql .= " HAVING distancia < $radius ";
}

$sql .= " ORDER BY p.created_at DESC";

try {
    $result = $conn->query($sql);

    $data = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Round distance for UI
            if (isset($row['distancia']) && $row['distancia'] !== null) {
                $row['distancia'] = round(floatval($row['distancia']), 2);
            }
            $data[] = $row;
        }
    } else {
        throw new Exception("Error en la consulta SQL: " . $conn->error);
    }

    echo json_encode($data);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage(), "sql" => $sql]);
}
