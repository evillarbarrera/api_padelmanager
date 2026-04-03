<?php
header("Content-Type: application/json");

require_once "../db.php";

$coach_id = $_GET['coach_id'] ?? 0;

if (!$coach_id) {
    echo json_encode(["status" => "error", "message" => "ID requerido"]);
    exit;
}

try {
    // 1. Obtener datos del Coach con SELECT * para evitar errores de columnas faltantes
    $sql = "SELECT * FROM usuarios WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $coach_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        echo json_encode(["status" => "error", "message" => "Usuario no encontrado"]);
        exit;
    }

    // 2. Extraer datos con fallbacks seguros por si no existen las columnas
    $created_at = $user['created_at'] ?? date('Y-m-d H:i:s');
    $mp_id = $user['mp_preapproval_id'] ?? '';
    $paypal_id = $user['paypal_sub_id'] ?? '';
    $plan_id = $user['plan_id'] ?? 1;

    // 3. Calcular días transcurridos de prueba
    $created = new DateTime($created_at);
    $now = new DateTime();
    $diff = $created->diff($now);
    $days_active = $diff->days;
    $trial_days = 90;
    $days_remaining = max(0, $trial_days - $days_active);

    // 4. Determinar si está Bloqueado (si pasaron 90 días y no hay método de pago)
    $is_blocked = ($days_remaining <= 0 && empty($mp_id) && empty($paypal_id));

    $plan_names = [1 => "Emprendedor", 2 => "Inicial 20", 3 => "Pro 40", 4 => "Elite"];
    $plan_prices = [1 => 0, 2 => 19990, 3 => 29990, 4 => 49990];

    echo json_encode([
        "status" => $is_blocked ? "blocked" : "active",
        "days_remaining" => $days_remaining,
        "plan_id" => (int)$plan_id,
        "plan_name" => $plan_names[$plan_id] ?? "Sin Plan",
        "price_clp" => $plan_prices[$plan_id] ?? 0,
        "subscription_status" => $user['subscription_status'] ?? 'inactive',
        "has_payment_method" => (!empty($mp_id) || !empty($paypal_id)),
        "message" => $is_blocked ? "Tu periodo de prueba ha finalizado. Debes asociar una tarjeta para continuar." : "Suscripción activa"
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
