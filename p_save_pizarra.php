<?php
// p_save_pizarra.php 
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, X-Authorization, Content-Type");
    http_response_code(200);
    exit;
}

require_once "db.php";

$data = json_decode(file_get_contents("php://input"), true);

if (!$data || !isset($data['id_entrenador'])) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Datos incompletos"]);
    exit;
}

$id = isset($data['id']) ? (int)$data['id'] : 0;
$id_entrenador = (int)$data['id_entrenador'];
$nombre = $data['nombre'] ?? 'Nuevo Análisis';
$notas = $data['notas'] ?? '';
$players_data = json_encode($data['players_data']);
$marcador_data = json_encode($data['marcador_data']);
$stats_data = json_encode($data['stats_data']);
$elements_data = json_encode($data['elements_data']);
$drawings_data = json_encode($data['drawings_data'] ?? []);

if ($id > 0) {
    // Update
    $stmt = $conn->prepare("UPDATE pizarra_tactica SET 
        nombre_sesion = ?, notas = ?, players_data = ?, marcador_data = ?, 
        stats_data = ?, elements_data = ?, drawings_data = ? 
        WHERE id = ? AND id_entrenador = ?");
    $stmt->bind_param("sssssssii", $nombre, $notas, $players_data, $marcador_data, $stats_data, $elements_data, $drawings_data, $id, $id_entrenador);
} else {
    // New
    $stmt = $conn->prepare("INSERT INTO pizarra_tactica (id_entrenador, nombre_sesion, notas, players_data, marcador_data, stats_data, elements_data, drawings_data) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssssss", $id_entrenador, $nombre, $notas, $players_data, $marcador_data, $stats_data, $elements_data, $drawings_data);
}

if ($stmt->execute()) {
    $inserted_id = ($id > 0) ? $id : $conn->insert_id;
    echo json_encode(["success" => true, "id" => $inserted_id, "message" => "Guardado con éxito"]);
} else {
    echo json_encode(["success" => false, "message" => $conn->error]);
}
?>
