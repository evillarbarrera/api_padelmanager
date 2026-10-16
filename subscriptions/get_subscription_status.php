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

$user_id = intval($_GET['coach_id'] ?? ($_GET['user_id'] ?? 0));
$requested_role = strtolower(trim($_GET['role'] ?? ($_GET['user_role'] ?? '')));

if (!$user_id) {
    echo json_encode(["status" => "error", "message" => "ID de usuario requerido"]);
    exit;
}

try {
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
            "days_remaining" => 90,
            "plan_id" => (int)($user['plan_id'] ?? 0),
            "plan_name" => "Acceso Ilimitado",
            "price_clp" => 0,
            "subscription_status" => "active",
            "has_payment_method" => true,
            "message" => "Acceso activo"
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

    // 2. Extraer datos del usuario objetivo
    $created_at = $target_user['created_at'] ?? date('Y-m-d H:i:s');
    $mp_id = $target_user['mp_preapproval_id'] ?? '';
    $paypal_id = $target_user['paypal_sub_id'] ?? '';
    $plan_id = (int)($target_user['plan_id'] ?? 0);
    $sub_status = $target_user['subscription_status'] ?? 'inactive';
    $has_payment_method = (!empty($mp_id) || !empty($paypal_id));

    // 3. Calcular días transcurridos de prueba (90 días = 3 meses)
    $created = new DateTime($created_at);
    $now = new DateTime();
    $diff = $created->diff($now);
    $days_active = (int)$diff->days;
    $trial_days = 90;
    $days_remaining = max(0, $trial_days - $days_active);

    $is_club = (strpos($effective_role, 'club') !== false || $effective_role === 'administrador_club' || $effective_role === 'admin_club');

    $is_blocked = false;
    $message = "Suscripción activa";

    if ($is_club) {
        // --- LÓGICA PARA CLUBES ---
        $plan_id = 4; // Plan Gestión de Club
        $plan_name = "Gestión de Club";
        $price_clp = 49990;

        if ($days_remaining <= 0) {
            if (!$has_payment_method) {
                $is_blocked = true;
                $sub_status = 'blocked';
                $message = "Tu periodo de prueba gratuita de 90 días para la gestión de tu club ha finalizado. Realiza el pago mensual de tu plan ($49.990 CLP) para continuar usando los servicios de canchas, reservas, turnos y torneos.";
            } else {
                $message = "Suscripción mensual activa.";
            }
        } else {
            $message = "Periodo de prueba activo ($days_remaining días restantes).";
        }
    } else {
        // --- LÓGICA PARA ENTRENADORES ---
        $plan_names = [0 => "Sin asignar", 1 => "Emprendedor", 2 => "Inicial 20", 3 => "Pro 40", 4 => "Elite Ilimitado"];
        $plan_prices = [0 => 0, 1 => 0, 2 => 19990, 3 => 29990, 4 => 49990];
        $plan_name = $plan_names[$plan_id] ?? "Sin Plan";
        $price_clp = $plan_prices[$plan_id] ?? 0;

        if ($days_remaining <= 0) {
            if ($plan_id == 0) {
                $is_blocked = true;
                $sub_status = 'blocked';
                $message = "Tu periodo de prueba de 90 días ha finalizado. Debes seleccionar un plan para continuar con los servicios de tu academia.";
            } elseif ($plan_id == 1) {
                $is_blocked = false;
                $message = "Plan Emprendedor activo";
            } elseif ($plan_id > 1 && !$has_payment_method) {
                $is_blocked = true;
                $sub_status = 'blocked';
                $message = "Tu periodo de prueba de 90 días ha finalizado. Para seguir utilizando tu calendario y gestionar alumnos, activa tu plan mensual.";
            } else {
                $message = "Suscripción mensual activa.";
            }
        } else {
            $message = "Periodo de prueba activo ($days_remaining días restantes).";
        }
    }

    echo json_encode([
        "status" => $is_blocked ? "blocked" : "active",
        "days_remaining" => $days_remaining,
        "plan_id" => $plan_id,
        "plan_name" => $plan_name,
        "price_clp" => $price_clp,
        "subscription_status" => $is_blocked ? "blocked" : $sub_status,
        "has_payment_method" => $has_payment_method,
        "message" => $message,
        "is_club" => $is_club
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
