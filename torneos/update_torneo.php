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
$check = $conn->query("SHOW COLUMNS FROM `torneos_v2` LIKE 'precio'");
if ($check && $check->num_rows == 0) {
    $conn->query("ALTER TABLE `torneos_v2` ADD `precio` DECIMAL(10,2) DEFAULT 0.00");
}
$checkPoster = $conn->query("SHOW COLUMNS FROM `torneos_v2` LIKE 'poster_url'");
if ($checkPoster && $checkPoster->num_rows == 0) {
    $conn->query("ALTER TABLE `torneos_v2` ADD `poster_url` VARCHAR(255) DEFAULT NULL");
}
$checkCanchas = $conn->query("SHOW COLUMNS FROM `torneos_v2` LIKE 'num_canchas'");
if ($checkCanchas && $checkCanchas->num_rows == 0) {
    $conn->query("ALTER TABLE `torneos_v2` ADD `num_canchas` INT DEFAULT 2");
}
$checkCanchasIds = $conn->query("SHOW COLUMNS FROM `torneos_v2` LIKE 'canchas_ids'");
if ($checkCanchasIds && $checkCanchasIds->num_rows == 0) {
    $conn->query("ALTER TABLE `torneos_v2` ADD `canchas_ids` TEXT DEFAULT NULL");
}

$updates = [];
$types = "";
$params = [];

if (isset($data->nombre)) {
    $updates[] = "nombre = ?";
    $types .= "s";
    $params[] = $data->nombre;
}

if (isset($data->descripcion)) {
    $updates[] = "descripcion = ?";
    $types .= "s";
    $params[] = $data->descripcion;
}

if (!empty($data->fecha_inicio)) {
    $updates[] = "fecha_inicio = ?";
    $types .= "s";
    $params[] = $data->fecha_inicio;
}

if (isset($data->fecha_fin)) {
    $updates[] = "fecha_fin = ?";
    $types .= "s";
    $params[] = !empty($data->fecha_fin) ? $data->fecha_fin : null;
}

if (isset($data->precio)) {
    $updates[] = "precio = ?";
    $types .= "d";
    $params[] = floatval($data->precio);
}

if (isset($data->formato_sets)) {
    $updates[] = "formato_sets = ?";
    $types .= "s";
    $params[] = $data->formato_sets;
}

if (isset($data->poster_url)) {
    $updates[] = "poster_url = ?";
    $types .= "s";
    $params[] = $data->poster_url;
}

if (isset($data->num_canchas)) {
    $updates[] = "num_canchas = ?";
    $types .= "i";
    $params[] = (int)$data->num_canchas;
}

if (isset($data->canchas_ids)) {
    $updates[] = "canchas_ids = ?";
    $types .= "s";
    $params[] = is_array($data->canchas_ids) ? json_encode($data->canchas_ids) : $data->canchas_ids;
}

if (empty($updates)) {
    echo json_encode(["status" => "success", "message" => "Nothing to update"]);
    exit();
}

$sql = "UPDATE torneos_v2 SET " . implode(", ", $updates) . " WHERE id = ?";
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
    echo json_encode(["status" => "success", "message" => "Torneo updated successfully"]);
} else {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Execute failed: " . $stmt->error]);
}

$stmt->close();
$conn->close();
?>
