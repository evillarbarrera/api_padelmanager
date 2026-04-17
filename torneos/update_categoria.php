<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include_once '../db.php';

$data = json_decode(file_get_contents("php://input"));

if(empty($data->id)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing Category ID"]);
    exit();
}

$id = (int)$data->id;
$nombre = isset($data->nombre) ? $conn->real_escape_string($data->nombre) : null;
$max_parejas = isset($data->max_parejas) ? (int)$data->max_parejas : null;
$puntos_repartir = isset($data->puntos_repartir) ? (int)$data->puntos_repartir : null;

$updates = [];
if ($nombre !== null) $updates[] = "nombre = '$nombre'";
if ($max_parejas !== null) $updates[] = "max_parejas = $max_parejas";
if ($puntos_repartir !== null) $updates[] = "puntos_repartir = $puntos_repartir";

if (empty($updates)) {
    echo json_encode(["status" => "success", "message" => "Nothing to update"]);
    exit();
}

$sql = "UPDATE torneo_categorias SET " . implode(", ", $updates) . " WHERE id = $id";

if ($conn->query($sql) === TRUE) {
    echo json_encode(["status" => "success", "message" => "Categoria updated successfully"]);
} else {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error updating record: " . $conn->error]);
}

$conn->close();
?>
