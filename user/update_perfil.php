<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$headers = getallheaders();
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

$expectedToken = 'Bearer ' . base64_encode("1|padel_academy");

if (empty($auth) || $auth !== $expectedToken) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$user_id = $data['user_id'] ?? 0;

if (!$user_id) {
    http_response_code(400);
    echo json_encode(["error" => "user_id is required"]);
    exit;
}

// 1. Update user data
$sqlUser = "UPDATE usuarios SET nombre = ?, telefono = ?, instagram = ?, facebook = ?, foto_perfil = ?, categoria = ?, descripcion = ? WHERE id = ?";
$stmtUser = $conn->prepare($sqlUser);
$stmtUser->bind_param("sssssssi", $data['nombre'], $data['telefono'], $data['instagram'], $data['facebook'], $data['foto_perfil'], $data['categoria'], $data['descripcion'], $user_id);
$stmtUser->execute();

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
