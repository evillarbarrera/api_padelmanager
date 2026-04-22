<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
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
$reservas_activas = isset($data['reservas_activas']) ? (int)$data['reservas_activas'] : 0;
$academia_activa = isset($data['academia_activa']) ? (int)$data['academia_activa'] : 1;

if (empty($nombre)) {
    http_response_code(400);
    echo json_encode(["error" => "Nombre es obligatorio"]);
    exit;
}

// 1. Actualizar tabla clubes (incluyendo región y comuna directas)
$sql = "UPDATE clubes SET nombre = ?, direccion = ?, region = ?, comuna = ?, telefono = ?, instagram = ?, email = ?, reservas_activas = ?, academia_activa = ? WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sssssssiii", $nombre, $direccion, $region, $comuna, $telefono, $instagram, $email, $reservas_activas, $academia_activa, $id);

if ($stmt->execute()) {
    // 2. Mantener tabla direcciones por compatibilidad
    $sqlCheck = "SELECT id FROM direcciones WHERE club_id = ?";
    $stmtCheck = $conn->prepare($sqlCheck);
    $stmtCheck->bind_param("i", $id);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();

    if ($resCheck->num_rows > 0) {
        $upd = $conn->prepare("UPDATE direcciones SET region = ?, comuna = ?, calle = ?, usuario_id = NULL WHERE club_id = ?");
        $upd->bind_param("sssi", $region, $comuna, $direccion, $id);
        $upd->execute();
    } else {
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
