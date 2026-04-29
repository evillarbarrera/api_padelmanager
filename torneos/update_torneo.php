<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Max-Age: 3600");

// Manejo de peticiones preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include_once '../db.php';

$data = json_decode(file_get_contents("php://input"));

if(empty($data->id)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing ID"]);
    exit();
}

$id = (int)$data->id;
$nombre = isset($data->nombre) ? $conn->real_escape_string($data->nombre) : null;
$descripcion = isset($data->descripcion) ? $conn->real_escape_string($data->descripcion) : null;
$fecha_inicio = isset($data->fecha_inicio) ? $conn->real_escape_string($data->fecha_inicio) : null;
$fecha_fin = isset($data->fecha_fin) ? $conn->real_escape_string($data->fecha_fin) : null;
$club_id = isset($data->club_id) ? (int)$data->club_id : null;
$tipo = isset($data->tipo) ? $conn->real_escape_string($data->tipo) : null;
$formato_grupos = isset($data->formato_grupos) ? (int)$data->formato_grupos : null;
$formato_sets = isset($data->formato_sets) ? $conn->real_escape_string($data->formato_sets) : null;
$inscripciones_abiertas = isset($data->inscripciones_abiertas) ? (int)$data->inscripciones_abiertas : null;

// Build update query dynamically
$updates = [];
if ($nombre !== null) $updates[] = "nombre = '$nombre'";
if ($descripcion !== null) $updates[] = "descripcion = '$descripcion'";
if ($fecha_inicio !== null) $updates[] = "fecha_inicio = '$fecha_inicio'";
if ($fecha_fin !== null) $updates[] = "fecha_fin = '$fecha_fin'";
if ($club_id !== null) $updates[] = "club_id = $club_id";
if ($tipo !== null) $updates[] = "tipo = '$tipo'";
if ($formato_grupos !== null) $updates[] = "formato_grupos = $formato_grupos";
if ($formato_sets !== null) $updates[] = "formato_sets = '$formato_sets'";
if ($inscripciones_abiertas !== null) $updates[] = "inscripciones_abiertas = $inscripciones_abiertas";

if (empty($updates)) {
    echo json_encode(["status" => "success", "message" => "Nothing to update"]);
    exit();
}

$sql = "UPDATE torneos_v2 SET " . implode(", ", $updates) . " WHERE id = $id";

if ($conn->query($sql) === TRUE) {
    echo json_encode(["status" => "success", "message" => "Torneo updated successfully"]);
} else {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error updating record: " . $conn->error]);
}

$conn->close();
?>
