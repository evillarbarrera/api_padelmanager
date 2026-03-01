<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once "../db.php";

// SCHEMA FIX: Asegurar que existe max_parejas para el control de cupos
$checkCol = $conn->query("SHOW COLUMNS FROM torneos_americanos LIKE 'max_parejas'");
if ($checkCol && $checkCol->num_rows == 0) {
    $conn->query("ALTER TABLE torneos_americanos ADD max_parejas INT DEFAULT 8 AFTER num_canchas");
}

$region = isset($_GET['region']) ? $_GET['region'] : '';
$comuna = isset($_GET['comuna']) ? $_GET['comuna'] : '';

$current_date = date("Y-m-d");
$show_all = isset($_GET['all']) && $_GET['all'] == '1';

// 1. Consulta básica
$sql = "SELECT t.*, c.nombre as club_nombre, c.direccion as club_direccion, 
               d.region as club_region, d.comuna as club_comuna,
               u.foto_perfil as admin_foto,
               (SELECT COUNT(*) FROM torneo_participantes WHERE torneo_id = t.id) as inscritos
        FROM torneos_americanos t 
        JOIN clubes c ON t.club_id = c.id 
        LEFT JOIN direcciones d ON d.club_id = c.id
        LEFT JOIN usuarios u ON t.creator_id = u.id
        WHERE 1=1";

if (!$show_all) {
    $sql .= " AND (t.estado != 'Cerrado' OR t.estado IS NULL OR t.estado = '') 
              AND t.fecha >= '$current_date'";
}

if (!empty($region)) {
    $sql .= " AND d.region = ?";
}

if (!empty($comuna)) {
    $sql .= " AND d.comuna = ?";
}

$sql .= " ORDER BY t.fecha ASC, t.hora_inicio ASC";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => "Error al preparar consulta: " . $conn->error, "sql" => $sql]);
    exit;
}

if (!empty($region) && !empty($comuna)) {
    $stmt->bind_param("ss", $region, $comuna);
} elseif (!empty($region)) {
    $stmt->bind_param("s", $region);
} elseif (!empty($comuna)) {
    $stmt->bind_param("s", $comuna);
}

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(["error" => "Error al ejecutar consulta: " . $stmt->error]);
    exit;
}

$result = $stmt->get_result();

$current_date = date("Y-m-d");

$torneos = [];
$hidden_count = 0;
while ($row = $result->fetch_assoc()) {
    $max = isset($row['max_parejas']) && $row['max_parejas'] > 0 ? (int)$row['max_parejas'] : 32;
    $inscritos = (int)($row['inscritos'] ?? 0);
    
    $row['max_parejas'] = $max;
    // Agregamos siempre, el front se encarga de decir si está lleno
    $torneos[] = $row;
}

// Para efectos de depuración si se llama manualmente
if (isset($_GET['debug'])) {
    echo json_encode([
        "debug" => [
            "current_date" => $current_date,
            "region_filter" => $region,
            "comuna_filter" => $comuna,
            "hidden_by_cupos" => $hidden_count,
            "total_found" => count($torneos) + $hidden_count
        ],
        "data" => $torneos
    ]);
} else {
    echo json_encode($torneos);
}
?>
