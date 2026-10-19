<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");
error_reporting(E_ALL);
ini_set('display_errors', 0);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";
require_once "../pagos/mercado_pago_service.php";
require_once "../auth/auth_helper.php";
require_once "subscription_helper.php";

try {
    // 1. Obtener Input
    $input = json_decode(file_get_contents("php://input"), true);
    $user_id = intval($input['coach_id'] ?? ($input['user_id'] ?? 0));
    $plan_id = intval($input['plan_id'] ?? 0);
    $club_id = intval($input['club_id'] ?? 0);
    
    // Mercado Pago NO acepta '#' en la back_url. Usamos una URL limpia.
    $origin = "https://padelblox.cl/"; 

    if (!$user_id || !$plan_id) {
        echo json_encode(["status" => "error", "message" => "Datos incompletos"]);
        exit;
    }

    // 2. Obtener datos del Usuario
    $sqlUser = "SELECT * FROM usuarios WHERE id = ?";
    $stmt = $conn->prepare($sqlUser);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $coach = $stmt->get_result()->fetch_assoc();

    if (!$coach) {
        echo json_encode(["status" => "error", "message" => "Usuario no encontrado"]);
        exit;
    }

    // Si es usuario o club de PowerPadel: Servicio gratuito sin cobros
    if (isPowerPadelUser($conn, $user_id, $coach, $club_id)) {
        echo json_encode([
            "status" => "success",
            "is_free" => true,
            "message" => "PowerPadel es el club de prueba oficial. Cuenta con servicio gratuito permanente sin cobros."
        ]);
        exit;
    }

    // 3. Diccionario de Planes
    $user_role = strtolower($coach['rol'] ?? '');
    $is_club = (strpos($user_role, 'club') !== false);

    $plans = [
        1 => ["name" => "EMPRENDEDOR", "price" => 0],
        2 => ["name" => "INICIAL 20", "price" => 19990],
        3 => ["name" => "PRO 40", "price" => 29990],
        4 => ["name" => ($is_club ? "GESTIÓN DE CLUB" : "ELITE ILIMITADO"), "price" => 49990]
    ];

    $plan = $plans[$plan_id] ?? null;

    if (!$plan) {
        echo json_encode(["status" => "error", "message" => "Plan no válido"]);
        exit;
    }

    // 4. Calcular días transcurridos de prueba y vencimiento efectivo
    $now = new DateTime();
    $has_explicit_end = !empty($coach['subscription_end_date']);
    if ($has_explicit_end) {
        $expiration = new DateTime($coach['subscription_end_date']);
    } else {
        $created_at = $coach['created_at'] ?? date('Y-m-d H:i:s');
        $created = new DateTime($created_at);
        $expiration = clone $created;
        $expiration->modify('+90 days');
    }

    $is_expired = ($now > $expiration);
    $days_remaining = $is_expired ? 0 : (int)$now->diff($expiration)->days;
    $force_payment = !empty($input['force_payment']) || !empty($input['renew']);

    // 5. Plan Emprendedor ($0) siempre es gratuito sin cobro
    if ($plan_id == 1) {
        $stmtUpd = $conn->prepare("UPDATE usuarios SET plan_id = 1, subscription_status = 'active' WHERE id = ?");
        $stmtUpd->bind_param("i", $user_id);
        $stmtUpd->execute();

        echo json_encode([
            "success" => true,
            "redirect" => false,
            "message" => "Plan EMPRENDEDOR ($0 CLP) activado con éxito."
        ]);
        exit;
    }

    // Si aún está dentro del período de prueba gratis y no forzó pago de renovación:
    if ($days_remaining > 0 && !$is_expired && !$force_payment) {
        $stmtUpd = $conn->prepare("UPDATE usuarios SET plan_id = ?, subscription_status = 'active' WHERE id = ?");
        $stmtUpd->bind_param("ii", $plan_id, $user_id);
        $stmtUpd->execute();

        echo json_encode([
            "success" => true,
            "redirect" => false,
            "message" => "Plan " . $plan['name'] . " asignado exitosamente durante tu periodo de prueba ($days_remaining días restantes). Al vencer se cobrará la mensualidad."
        ]);
        exit;
    }

    // 6. Si el periodo venció o requiere pago para renovar +1 mes:
    // Generar Checkout Pro de Mercado Pago (Permite Webpay Plus, Redcompra, Débito, Crédito y saldo MP)
    $clientOrigin = !empty($input['origin']) ? $input['origin'] : "https://padelblox.cl/mi-plan";
    $subData = [
        "payer_email" => $coach['usuario'] ?? ($coach['email'] ?? 'contacto@padelblox.cl'),
        "plan_name" => $plan['name'],
        "amount" => $plan['price'],
        "user_id" => $user_id,
        "coach_id" => $user_id,
        "plan_id" => $plan_id,
        "origin" => $clientOrigin
    ];

    $preference = MercadoPagoService::createSubscriptionRenewalPreference($subData);

    if ($preference && isset($preference['init_point'])) {
        echo json_encode([
            "success" => true,
            "init_point" => $preference['init_point'],
            "id" => $preference['id'] ?? null,
            "redirect" => true,
            "message" => "Preferencia de pago generada exitosamente"
        ]);
    } else {
        // Fallback a preapproval si preferencia falla
        $subscription = MercadoPagoService::createPreApproval($subData);
        if ($subscription && isset($subscription['init_point'])) {
            echo json_encode([
                "success" => true,
                "init_point" => $subscription['init_point'],
                "id" => $subscription['id'] ?? null,
                "redirect" => true
            ]);
        } else {
            $error_msg = $preference['message'] ?? ($subscription['message'] ?? "Error al conectar con pasarela de pago");
            echo json_encode(["success" => false, "message" => $error_msg]);
        }
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
