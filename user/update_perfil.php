<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ob_start();

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
    ob_end_clean();
    sendUnauthorized();
}

$data = json_decode(file_get_contents("php://input"), true);
$user_id = intval($data['user_id'] ?? 0);

if (!$user_id) {
    ob_end_clean();
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
    if ($stmtUser) {
        $stmtUser->bind_param($types, ...$values);
        $stmtUser->execute();
        $stmtUser->close();
    }
}

// 2. Update or Insert address ONLY if address fields were passed
$hasAddressFields = isset($data['region']) || isset($data['comuna']) || isset($data['calle']) || isset($data['numero_casa']) || isset($data['referencia']) || isset($data['latitud']) || isset($data['longitud']);

if ($hasAddressFields) {
    $sqlCheckAddr = "SELECT id FROM direcciones WHERE usuario_id = ?";
    $stmtCheck = $conn->prepare($sqlCheckAddr);
    if ($stmtCheck) {
        $stmtCheck->bind_param("i", $user_id);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();

        $reg = $data['region'] ?? '';
        $com = $data['comuna'] ?? '';
        $cal = $data['calle'] ?? '';
        $num = $data['numero_casa'] ?? '';
        $ref = $data['referencia'] ?? '';
        $lat = isset($data['latitud']) && is_numeric($data['latitud']) ? floatval($data['latitud']) : null;
        $lng = isset($data['longitud']) && is_numeric($data['longitud']) ? floatval($data['longitud']) : null;

        if ($resCheck->num_rows > 0) {
            // Update
            $sqlAddr = "UPDATE direcciones SET region = ?, comuna = ?, calle = ?, numero_casa = ?, referencia = ?, latitud = ?, longitud = ? WHERE usuario_id = ?";
            $stmtAddr = $conn->prepare($sqlAddr);
            if ($stmtAddr) {
                $stmtAddr->bind_param("sssssddi", $reg, $com, $cal, $num, $ref, $lat, $lng, $user_id);
                $stmtAddr->execute();
                $stmtAddr->close();
            }
        } else {
            // Insert
            $sqlAddr = "INSERT INTO direcciones (usuario_id, region, comuna, calle, numero_casa, referencia, latitud, longitud) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmtAddr = $conn->prepare($sqlAddr);
            if ($stmtAddr) {
                $stmtAddr->bind_param("isssssdd", $user_id, $reg, $com, $cal, $num, $ref, $lat, $lng);
                $stmtAddr->execute();
                $stmtAddr->close();
            }
        }
        $stmtCheck->close();
    }
}

ob_end_clean();
echo json_encode(["success" => true, "message" => "Profile updated successfully"]);
