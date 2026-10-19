<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";
require_once "subscription_helper.php";

$user_id = intval($_GET['coach_id'] ?? ($_GET['user_id'] ?? 0));
$requested_role = strtolower(trim($_GET['role'] ?? ($_GET['user_role'] ?? '')));

if (!$user_id) {
    echo json_encode(["status" => "error", "message" => "ID de usuario requerido"]);
    exit;
}

try {
    // 0. Auto-migración defensiva de esquema
    ensureSubscriptionTablesAndColumns($conn);

    // 1. Obtener datos del Usuario
    $sql = "SELECT * FROM usuarios WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        echo json_encode(["status" => "error", "message" => "Usuario no encontrado"]);
        exit;
    }

    $db_role = strtolower($user['rol'] ?? 'jugador');
    $effective_role = !empty($requested_role) ? $requested_role : $db_role;

    // Super Admin y Jugador NUNCA se bloquean
    if ($effective_role === 'administrador' || $effective_role === 'superadmin' || $effective_role === 'jugador' || $effective_role === 'alumno') {
        echo json_encode([
            "status" => "active",
            "is_blocked" => false,
            "days_remaining" => 90,
            "plan_id" => (int)($user['plan_id'] ?? 0),
            "plan_name" => "Acceso Ilimitado",
            "price_clp" => 0,
            "subscription_status" => "active",
            "has_payment_method" => true,
            "message" => "Acceso activo",
            "is_club" => false,
            "billing_history" => []
        ]);
        exit;
    }

    // Si es Staff de club, verificar la suscripción del Club Admin correspondiente
    $target_user = $user;
    if ($effective_role === 'staff_club' || strpos($effective_role, 'staff') !== false) {
        $stmtOwner = $conn->prepare("
            SELECT u.* FROM usuarios_clubes uc 
            JOIN clubes c ON uc.club_id = c.id 
            JOIN usuarios u ON c.admin_id = u.id 
            WHERE uc.usuario_id = ? AND uc.activo = 1 
            LIMIT 1
        ");
        if ($stmtOwner) {
            $stmtOwner->bind_param("i", $user_id);
            $stmtOwner->execute();
            $owner = $stmtOwner->get_result()->fetch_assoc();
            if ($owner) {
                $target_user = $owner;
            }
        }
    }

    $club_id = intval($_GET['club_id'] ?? 0);
    if (!$club_id && !empty($user['club_id'])) {
        $club_id = intval($user['club_id']);
    }

    // 2. Evaluar estado centralizado de suscripción mensual y bloqueo
    $response = evaluateSubscriptionStatus($target_user, $effective_role, $conn, $club_id);
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
