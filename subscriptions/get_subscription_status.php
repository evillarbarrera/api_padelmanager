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
    $plan_id = $user['plan_id'] ?? 0;

    // 3. Calcular días transcurridos de prueba
    $created = new DateTime($created_at);
    $now = new DateTime();
    $diff = $created->diff($now);
    $days_active = $diff->days;
    $trial_days = 90;
    $days_remaining = max(0, $trial_days - $days_active);

    // 4. Determinar si está Bloqueado
    // - Si le quedan días de prueba, NO está bloqueado (status: active)
    // - Si se acabaron los días:
    //    - Si NO tiene plan (plan_id == 0), se bloquea para que elija uno.
    //    - Si tiene plan Emprendedor (1), es gratis, NO se bloquea.
    //    - Si tiene un plan de pago (> 1), se bloquea si no tiene medio de pago.
    
    $is_blocked = false;
    if ($days_remaining <= 0) {
        if ($plan_id == 0) {
            $is_blocked = true;
        } elseif ($plan_id > 1 && empty($mp_id) && empty($paypal_id)) {
            $is_blocked = true;
        }
    }

    $plan_names = [0 => "Sin asignar", 1 => "Emprendedor", 2 => "Inicial 20", 3 => "Pro 40", 4 => "Elite"];
    $plan_prices = [0 => 0, 1 => 0, 2 => 19990, 3 => 29990, 4 => 49990];

    echo json_encode([
        "status" => $is_blocked ? "blocked" : "active",
        "days_remaining" => $days_remaining,
        "plan_id" => (int)$plan_id,
        "plan_name" => $plan_names[$plan_id] ?? "Sin Plan",
        "price_clp" => $plan_prices[$plan_id] ?? 0,
        "subscription_status" => $user['subscription_status'] ?? 'inactive',
        "has_payment_method" => (!empty($mp_id) || !empty($paypal_id)),
        "message" => $is_blocked ? "Tu periodo de prueba ha finalizado. Debes seleccionar un plan para continuar." : "Suscripción activa"
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
