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

try {
    // 1. Obtener Input
    $input = json_decode(file_get_contents("php://input"), true);
    $user_id = intval($input['coach_id'] ?? ($input['user_id'] ?? 0));
    $plan_id = intval($input['plan_id'] ?? 0);
    
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

    // 3. Diccionario de Planes
    $plans = [
        1 => ["name" => "EMPRENDEDOR", "price" => 0],
        2 => ["name" => "INICIAL 20", "price" => 19990],
        3 => ["name" => "PRO 40", "price" => 29990],
        4 => ["name" => "GESTIÓN DE CLUB", "price" => 49990]
    ];

    $plan = $plans[$plan_id] ?? null;

    if (!$plan) {
        echo json_encode(["status" => "error", "message" => "Plan no válido"]);
        exit;
    }

    // 4. Calcular días transcurridos de prueba (3 meses / 90 días)
    $created_at = $coach['created_at'] ?? date('Y-m-d H:i:s');
    $created = new DateTime($created_at);
    $now = new DateTime();
    $diff = $created->diff($now);
    $days_active = $diff->days;
    $trial_days = 90;
    $days_remaining = max(0, $trial_days - $days_active);

    // 5. Si está dentro del período de prueba (3 meses gratis) o es plan gratuito:
    // Asignar el plan directamente sin requerir tarjeta de crédito.
    if ($days_remaining > 0 || $plan_id == 1) {
        $stmtUpd = $conn->prepare("UPDATE usuarios SET plan_id = ?, subscription_status = 'active' WHERE id = ?");
        $stmtUpd->bind_param("ii", $plan_id, $user_id);
        $stmtUpd->execute();

        echo json_encode([
            "success" => true,
            "redirect" => false,
            "message" => "Plan " . $plan['name'] . " asignado exitosamente con 3 meses gratis. No requiere tarjeta de crédito hasta finalizar los $days_remaining días de prueba."
        ]);
        exit;
    }

    // 6. Si los 3 meses gratis ya finalizaron, requerir vincular tarjeta de crédito vía Mercado Pago
    $subData = [
        "payer_email" => $coach['usuario'] ?? ($coach['email'] ?? 'test@padelblox.cl'),
        "plan_name" => $plan['name'],
        "amount" => $plan['price'],
        "coach_id" => $user_id,
        "plan_id" => $plan_id,
        "origin" => $origin
    ];

    $subscription = MercadoPagoService::createPreApproval($subData);

    if ($subscription && isset($subscription['init_point'])) {
        echo json_encode([
            "success" => true,
            "init_point" => $subscription['init_point'],
            "id" => $subscription['id'] ?? null,
            "redirect" => true
        ]);
    } else {
        $error_msg = $subscription['message'] ?? "Error al conectar con Mercado Pago";
        echo json_encode(["success" => false, "message" => $error_msg]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
