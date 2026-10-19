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

// Silent schema checks for all torneos_v2 columns
$cols_to_check = [
    'precio' => "DECIMAL(10,2) DEFAULT 0.00",
    'poster_url' => "VARCHAR(255) DEFAULT NULL",
    'num_canchas' => "INT DEFAULT 2",
    'canchas_ids' => "TEXT DEFAULT NULL",
    'inscripciones_abiertas' => "BOOLEAN DEFAULT FALSE",
    'estado' => "ENUM('Abierto', 'En Curso', 'Finalizado', 'Cerrado') DEFAULT 'Abierto'",
    'tipo' => "VARCHAR(50) DEFAULT 'Grupos + Playoffs'",
    'formato_grupos' => "INT DEFAULT 4",
    'club_id' => "INT NOT NULL DEFAULT 1"
];
foreach ($cols_to_check as $col => $def) {
    $resCheck = $conn->query("SHOW COLUMNS FROM `torneos_v2` LIKE '$col'");
    if ($resCheck && $resCheck->num_rows == 0) {
        @$conn->query("ALTER TABLE `torneos_v2` ADD `$col` $def");
    }
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

if (isset($data->inscripciones_abiertas)) {
    $updates[] = "inscripciones_abiertas = ?";
    $types .= "i";
    $params[] = !empty($data->inscripciones_abiertas) ? 1 : 0;
}

if (isset($data->estado)) {
    $updates[] = "estado = ?";
    $types .= "s";
    $params[] = $data->estado;
}

if (isset($data->tipo)) {
    $updates[] = "tipo = ?";
    $types .= "s";
    $params[] = $data->tipo;
}

if (isset($data->formato_grupos)) {
    $updates[] = "formato_grupos = ?";
    $types .= "i";
    $params[] = (int)$data->formato_grupos;
}

if (isset($data->club_id)) {
    $updates[] = "club_id = ?";
    $types .= "i";
    $params[] = (int)$data->club_id;
}

if (empty($updates)) {
    echo json_encode(["status" => "success", "message" => "No hay cambios para actualizar"]);
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
    $msg = "Torneo actualizado exitosamente";
    if (isset($data->inscripciones_abiertas)) {
        $msg = !empty($data->inscripciones_abiertas) 
            ? "Inscripciones abiertas correctamente. El torneo ahora es visible para los jugadores en la App." 
            : "Inscripciones cerradas correctamente.";
    }
    echo json_encode(["status" => "success", "message" => $msg]);
} else {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Execute failed: " . $stmt->error]);
}

$stmt->close();
$conn->close();
?>
