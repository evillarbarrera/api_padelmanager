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

$role = strtolower($_GET['role'] ?? ($_GET['user_role'] ?? ''));

// Si se pasa coach_id o user_id, obtenemos el rol del usuario desde la BD
$user_id = intval($_GET['coach_id'] ?? ($_GET['user_id'] ?? 0));
if ($user_id > 0) {
    $stmt = $conn->prepare("SELECT rol FROM usuarios WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    if ($res && !empty($res['rol'])) {
        $role = strtolower($res['rol']);
    }
}

// Para Clubes (administrador_club, club, staff_club): Plan Único "GESTIÓN DE CLUB" $49.990 CLP / mes
if (strpos($role, 'club') !== false || $role === 'administrador_club') {
    $plans = [
        [
            "id" => 4,
            "name" => "GESTIÓN DE CLUB",
            "price_clp" => 49990,
            "description" => "Plan integral para la gestión completa del club: canchas, reservas, ventas e inventario con 3 meses gratis.",
            "type" => "fixed"
        ]
    ];
} else {
    // Para Entrenadores / Coached
    $plans = [
        ["id" => 1, "name" => "EMPRENDEDOR", "price_clp" => 0, "description" => "Hasta 5 alumnos, con comisión", "type" => "commission"],
        ["id" => 2, "name" => "INICIAL 20", "price_clp" => 19990, "description" => "Hasta 20 alumnos, sin comisión", "type" => "fixed"],
        ["id" => 3, "name" => "PRO 40", "price_clp" => 29990, "description" => "Hasta 40 alumnos, sin comisión", "type" => "fixed"],
        ["id" => 4, "name" => "ELITE ILIMITADO", "price_clp" => 49990, "description" => "Alumnos ilimitados, sin comisión", "type" => "fixed"]
    ];
}

echo json_encode($plans);
?>
