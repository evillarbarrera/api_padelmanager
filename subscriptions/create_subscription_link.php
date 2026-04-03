<?php
header("Content-Type: application/json");
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once "../db.php";
require_once "../pagos/mercado_pago_service.php";
require_once "../auth/auth_helper.php";

try {
    // 1. Obtener Input
    $input = json_decode(file_get_contents("php://input"), true);
    $coach_id = $input['coach_id'] ?? 0;
    $plan_id = $input['plan_id'] ?? 0;
    
    // Mercado Pago NO acepta '#' en la back_url. Usamos una URL limpia.
    $origin = "https://padelmanager.cl/"; 

    if (!$coach_id || !$plan_id) {
        echo json_encode(["status" => "error", "message" => "Datos incompletos"]);
        exit;
    }

    // 2. Obtener datos del Coach (Robusto por si faltan columnas)
    $sqlCoach = "SELECT * FROM usuarios WHERE id = ?";
    $stmt = $conn->prepare($sqlCoach);
    $stmt->bind_param("i", $coach_id);
    $stmt->execute();
    $coach = $stmt->get_result()->fetch_assoc();

    if (!$coach) {
        echo json_encode(["status" => "error", "message" => "Coach no encontrado"]);
        exit;
    }

    // 3. Diccionario de Planes
    $plans = [
        1 => ["name" => "EMPRENDEDOR", "price" => 0],
        2 => ["name" => "INICIAL 20", "price" => 19990],
        3 => ["name" => "PRO 40", "price" => 29990],
        4 => ["name" => "ELITE ILIMITADO", "price" => 49990]
    ];

    $plan = $plans[$plan_id] ?? null;

    if (!$plan) {
        echo json_encode(["status" => "error", "message" => "Plan no válido"]);
        exit;
    }

    // 4. Crear Suscripción en Mercado Pago
    $subData = [
        "payer_email" => $coach['email'] ?? 'test@padelmanager.cl',
        "plan_name" => $plan['name'],
        "amount" => $plan['price'],
        "coach_id" => $coach_id,
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
