<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$user_id = $_GET['user_id'] ?? 0;
if (!$user_id) {
    // Try header authorization or query token
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $headers['X-Authorization'] ?? '';
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $decoded = base64_decode($matches[1]);
        $parts = explode('|', $decoded);
        $user_id = intval($parts[0] ?? 0);
    }
}

if (!$user_id) {
    ob_clean();
    http_response_code(401);
    header("Content-Type: application/json");
    echo json_encode(["success" => false, "error" => "Usuario no autenticado o ID no proporcionado"]);
    exit;
}

// 1. Obtener perfil de usuario
$stmtUser = $conn->prepare("SELECT id, nombre, usuario as email, rol, RUT, telefono, fecha_nacimiento, genero, nivel, categoria FROM usuarios WHERE id = ?");
$stmtUser->bind_param("i", $user_id);
$stmtUser->execute();
$userData = $stmtUser->get_result()->fetch_assoc();

if (!$userData) {
    ob_clean();
    http_response_code(404);
    header("Content-Type: application/json");
    echo json_encode(["success" => false, "error" => "Usuario no encontrado"]);
    exit;
}

// 2. Obtener reservas asociadas
$stmtRes = $conn->prepare("SELECT id, cancha_id, fecha, hora_inicio, hora_fin, precio, pagado, estado, created_at FROM reservas_cancha WHERE usuario_id = ? OR jugador2_id = ? OR jugador3_id = ? OR jugador4_id = ?");
$stmtRes->bind_param("iiii", $user_id, $user_id, $user_id, $user_id);
$stmtRes->execute();
$reservas = $stmtRes->get_result()->fetch_all(MYSQLI_ASSOC);

// 3. Obtener consentimientos otorgados
$consentimientos = [];
$stmtCons = $conn->prepare("SELECT consent_type, status, policy_version, created_at FROM user_consents_log WHERE usuario_id = ?");
if ($stmtCons) {
    $stmtCons->bind_param("i", $user_id);
    $stmtCons->execute();
    $consentimientos = $stmtCons->get_result()->fetch_all(MYSQLI_ASSOC);
}

$exportData = [
    "export_info" => [
        "platform" => "PadelBlox",
        "generated_at" => date('Y-m-d H:i:s'),
        "legal_framework" => "Ley N° 19.628 (Chile) - Derecho de Acceso y Portabilidad"
    ],
    "user_profile" => $userData,
    "reservations" => $reservas,
    "consent_history" => $consentimientos
];

ob_clean();
header("Content-Type: application/json");
header('Content-Disposition: attachment; filename="padelblox_export_user_' . $user_id . '.json"');
echo json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
