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

require_once "../db.php";

$headers = getallheaders();
require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}

$data = json_decode(file_get_contents("php://input"), true);
$user_id = $data['user_id'] ?? 0;

if (!$user_id) {
    http_response_code(400);
    echo json_encode(["error" => "user_id is required"]);
    exit;
}

// --- PARTIAL UPDATE LOGIC ---
$fields = [];
$values = [];
$types = "";

$updatableFields = [
    'nombre' => 's',
    'telefono' => 's',
    'instagram' => 's',
    'facebook' => 's',
    'foto_perfil' => 's',
    'categoria' => 's',
    'descripcion' => 's',
    'banco_titular' => 's',
    'banco_rut' => 's',
    'banco_nombre' => 's',
    'banco_tipo_cuenta' => 's',
    'banco_numero_cuenta' => 's',
    'mp_collector_id' => 's'
];

foreach ($updatableFields as $field => $type) {
    if (isset($data[$field])) {
        $fields[] = "$field = ?";
        $values[] = $data[$field];
        $types .= $type;
    }
}

// Special case for 'nivel' (alias for 'categoria' in some frontend calls)
if (isset($data['nivel']) && !isset($data['categoria'])) {
    $fields[] = "categoria = ?";
    $values[] = $data['nivel'];
    $types .= 's';
}

if (!empty($fields)) {
    $sqlUser = "UPDATE usuarios SET " . implode(", ", $fields) . " WHERE id = ?";
    $values[] = $user_id;
    $types .= "i";
    
    $stmtUser = $conn->prepare($sqlUser);
    $stmtUser->bind_param($types, ...$values);
    $stmtUser->execute();
}


// 2. Update or Insert address
$sqlCheckAddr = "SELECT id FROM direcciones WHERE usuario_id = ?";
$stmtCheck = $conn->prepare($sqlCheckAddr);
$stmtCheck->bind_param("i", $user_id);
$stmtCheck->execute();
$resCheck = $stmtCheck->get_result();

if ($resCheck->num_rows > 0) {
    // Update
    $sqlAddr = "UPDATE direcciones SET region = ?, comuna = ?, calle = ?, numero_casa = ?, referencia = ?, latitud = ?, longitud = ? WHERE usuario_id = ?";
    $stmtAddr = $conn->prepare($sqlAddr);
    $lat = $data['latitud'] ?? null; // Allow null
    $lng = $data['longitud'] ?? null;
    $stmtAddr->bind_param("sssssddi", $data['region'], $data['comuna'], $data['calle'], $data['numero_casa'], $data['referencia'], $lat, $lng, $user_id);
} else {
    // Insert
    $sqlAddr = "INSERT INTO direcciones (usuario_id, region, comuna, calle, numero_casa, referencia, latitud, longitud) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmtAddr = $conn->prepare($sqlAddr);
    $lat = $data['latitud'] ?? null;
    $lng = $data['longitud'] ?? null;
    $stmtAddr->bind_param("isssssdd", $user_id, $data['region'], $data['comuna'], $data['calle'], $data['numero_casa'], $data['referencia'], $lat, $lng);
}
$stmtAddr->execute();

echo json_encode(["success" => true, "message" => "Profile updated successfully"]);
