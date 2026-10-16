<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);

$token = $data['token'] ?? '';
$newPassword = $data['password'] ?? '';

if (empty($token) || empty($newPassword)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Token y nueva contraseña son obligatorios"]);
    exit;
}

// 1. Buscar usuario por token y validar expiración
$sql = "SELECT id FROM usuarios WHERE reset_token = ? AND reset_expires > NOW()";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();

if ($user = $result->fetch_assoc()) {
    $user_id = $user['id'];
    
    // 2. Hash de la nueva contraseña
    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    
    // 3. Actualizar contraseña y limpiar token
    $stmtUpdate = $conn->prepare("UPDATE usuarios SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
    $stmtUpdate->bind_param("si", $passwordHash, $user_id);
    
    if ($stmtUpdate->execute()) {
        echo json_encode(["success" => true, "message" => "Contraseña actualizada con éxito"]);
    } else {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error al actualizar la contraseña"]);
    }
} else {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "El enlace es inválido o ha expirado"]);
}
?>
