<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$headers = getallheaders();
require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
if (!$data || !isset($data['id'])) {
    http_response_code(400);
    echo json_encode(["error" => "Datos inválidos o falta ID del club"]);
    exit;
}

$id = $data['id'];
$nombre = $data['nombre'] ?? '';
$direccion = $data['direccion'] ?? '';
$region = $data['region'] ?? '';
$comuna = $data['comuna'] ?? '';
$telefono = $data['telefono'] ?? '';
$instagram = $data['instagram'] ?? '';
$email = $data['email'] ?? '';

if (empty($nombre)) {
    http_response_code(400);
    echo json_encode(["error" => "Nombre es obligatorio"]);
    exit;
}

// 1. Actualizar tabla clubes
$sql = "UPDATE clubes SET nombre = ?, direccion = ?, telefono = ?, instagram = ?, email = ? WHERE id = ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => "Error al preparar consulta de clubes: " . $conn->error]);
    exit;
}

$stmt->bind_param("sssssi", $nombre, $direccion, $telefono, $instagram, $email, $id);

if ($stmt->execute()) {
    // 2. Actualizar o Insertar en tabla direcciones
    // Primero verificamos si ya existe una dirección para este club
    $sqlCheck = "SELECT id FROM direcciones WHERE club_id = ?";
    $stmtCheck = $conn->prepare($sqlCheck);
    
    if (!$stmtCheck) {
        echo json_encode(["success" => true, "message" => "Club actualizado, pero error al preparar verificación de dirección: " . $conn->error]);
        exit;
    }

    $stmtCheck->bind_param("i", $id);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();

    if ($resCheck->num_rows > 0) {
        // Update: Aseguramos que usuario_id sea NULL para que el FK no falle si estaba vacío o con 0
        $upd = $conn->prepare("UPDATE direcciones SET region = ?, comuna = ?, calle = ?, usuario_id = NULL WHERE club_id = ?");
        $upd->bind_param("sssi", $region, $comuna, $direccion, $id);
        $upd->execute();
    } else {
        // Insert: Especificamos usuario_id como NULL explícitamente
        $ins = $conn->prepare("INSERT INTO direcciones (club_id, usuario_id, region, comuna, calle) VALUES (?, NULL, ?, ?, ?)");
        $ins->bind_param("isss", $id, $region, $comuna, $direccion);
        $ins->execute();
    }

    echo json_encode(["success" => true, "message" => "Club actualizado correctamente"]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al ejecutar update club: " . $stmt->error]);
}
?>
