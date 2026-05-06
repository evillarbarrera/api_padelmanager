<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Max-Age: 3600");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', 0);
error_reporting(E_ALL);

include_once '../db.php';

$data = json_decode(file_get_contents("php://input"));

if(empty($data->id)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing ID"]);
    exit();
}

$id = (int)$data->id;

// Silent schema check for precio (Safe version)
$check = $conn->query("SHOW COLUMNS FROM `torneos_americanos` LIKE 'precio'");
if ($check && $check->num_rows == 0) {
    $conn->query("ALTER TABLE `torneos_americanos` ADD `precio` DECIMAL(10,2) DEFAULT 0.00");
}

$updates = [];
$types = "";
$params = [];

if (isset($data->nombre)) {
    $updates[] = "nombre = ?";
    $types .= "s";
    $params[] = $data->nombre;
}

if (!empty($data->fecha)) {
    $updates[] = "fecha = ?";
    $types .= "s";
    $params[] = $data->fecha;
}

if (!empty($data->hora_inicio)) {
    $updates[] = "hora_inicio = ?";
    $types .= "s";
    $params[] = $data->hora_inicio;
}

if (isset($data->precio)) {
    $updates[] = "precio = ?";
    $types .= "d";
    $params[] = floatval($data->precio);
}

if (isset($data->tiempo_por_partido)) {
    $updates[] = "tiempo_por_partido = ?";
    $types .= "i";
    $params[] = (int)$data->tiempo_por_partido;
}

if (empty($updates)) {
    echo json_encode(["status" => "success", "message" => "Nothing to update"]);
    exit();
}

$sql = "UPDATE torneos_americanos SET " . implode(", ", $updates) . " WHERE id = ?";
$types .= "i";
$params[] = $id;

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Prepare failed: " . $conn->error]);
    exit();
}

$stmt->bind_param($types, ...$params);

if ($stmt->execute()) {
    echo json_encode(["status" => "success", "message" => "Torneo Americano updated successfully"]);
} else {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Execute failed: " . $stmt->error]);
}

$stmt->close();
$conn->close();
?>
