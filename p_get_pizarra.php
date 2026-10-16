<?php
// p_get_pizarra.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, X-Authorization, Content-Type");
    http_response_code(200);
    exit;
}

require_once "db.php";

$id_entrenador = isset($_GET['id_entrenador']) ? (int)$_GET['id_entrenador'] : 0;

if ($id_entrenador === 0) {
    echo json_encode(["success" => true, "data" => []]);
    exit;
}

$sql = "SELECT * FROM pizarra_tactica WHERE id_entrenador = ? ORDER BY fecha_actualizacion DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id_entrenador);
$stmt->execute();
$result = $stmt->get_result();

$tacticas = [];
while ($row = $result->fetch_assoc()) {
    // Decoding JSON fields for easier use in Angular
    $row['players_data'] = json_decode($row['players_data']);
    $row['marcador_data'] = json_decode($row['marcador_data']);
    $row['stats_data'] = json_decode($row['stats_data']);
    $row['elements_data'] = json_decode($row['elements_data']);
    $row['drawings_data'] = json_decode($row['drawings_data']);
    $tacticas[] = $row;
}

echo json_encode([
    "success" => true,
    "data" => $tacticas
]);
?>
