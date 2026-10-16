<?php
ob_start();
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

require_once "../db.php";
require_once "../db_helpers/user_consents_log.php";

$data = json_decode(file_get_contents("php://input"), true);
$user_id = $data['user_id'] ?? 0;
$confirmation = trim($data['confirmation'] ?? '');

if (!$user_id) {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $headers['X-Authorization'] ?? '';
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $decoded = base64_decode($matches[1]);
        $parts = explode('|', $decoded);
        $user_id = intval($parts[0] ?? 0);
    }
}

if (!$user_id || strtoupper($confirmation) !== 'ELIMINAR') {
    ob_clean();
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Debes confirmar la eliminación escribiendo 'ELIMINAR'"]);
    exit;
}

// 1. Anonimización (Data Masking) del usuario en la tabla usuarios
$anonNombre = "Usuario Anonimizado";
$anonEmail = "deleted_user_" . $user_id . "@padelblox.local";
$anonPass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

$stmt = $conn->prepare("UPDATE usuarios SET nombre = ?, usuario = ?, password = ?, RUT = NULL, telefono = NULL, fecha_nacimiento = NULL, genero = NULL WHERE id = ?");
$stmt->bind_param("sssi", $anonNombre, $anonEmail, $anonPass, $user_id);

if ($stmt->execute()) {
    // 2. Registrar evento en log de auditoría
    registrarConsentimiento($conn, $user_id, 'account_cancellation', 0, 'v1.0');
    
    ob_clean();
    echo json_encode([
        "success" => true,
        "message" => "Tu cuenta y datos personales han sido eliminados/anonimizados exitosamente conforme a la Ley N° 19.628."
    ]);
} else {
    ob_clean();
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Error al procesar la eliminación de la cuenta"]);
}
