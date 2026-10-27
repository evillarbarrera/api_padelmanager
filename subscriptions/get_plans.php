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

$role = strtolower($_GET['role'] ?? ($_GET['user_role'] ?? ''));
$club_id = intval($_GET['club_id'] ?? 0);

$target_user = null;
// Si se pasa coach_id o user_id, obtenemos el rol del usuario desde la BD
$user_id = intval($_GET['coach_id'] ?? ($_GET['user_id'] ?? 0));
if ($user_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM usuarios WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $target_user = $stmt->get_result()->fetch_assoc();
    if ($target_user) {
        if (!empty($target_user['rol'])) $role = strtolower($target_user['rol']);
        if (!$club_id && !empty($target_user['club_id'])) $club_id = intval($target_user['club_id']);
    }
}

$is_club = (strpos($role, 'club') !== false || $role === 'administrador_club' || $role === 'admin_club');

// 1. Caso Club de Prueba Oficial: PowerPadel (Solo para gestión de club)
if ($is_club && isPowerPadelClub($conn, $club_id, $user_id, $target_user, $role)) {
    echo json_encode([
        [
            "id" => 4,
            "name" => "CLUB DE PRUEBA (POWERPADEL)",
            "price_clp" => 0,
            "description" => "Club de prueba oficial: Servicio 100% bonificado con acceso ilimitado sin cobro de plan.",
            "type" => "fixed",
            "is_free" => true
        ]
    ]);
    exit;
}

// 2. Caso Entrenador Ilimitado: Nico Ruiz
if (!$is_club && isNicoRuizUser($conn, $user_id, $target_user)) {
    echo json_encode([
        [
            "id" => 4,
            "name" => "ELITE ILIMITADO (NICO RUIZ)",
            "price_clp" => 0,
            "description" => "Suscripción Ilimitada Bonificada Oficial (Academia Nico Ruiz).",
            "type" => "fixed",
            "is_free" => true
        ]
    ]);
    exit;
}

// 3. Para Clubes estándar
if ($is_club) {
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
    // 4. Para Entrenadores normales
    $plans = [
        ["id" => 1, "name" => "EMPRENDEDOR", "price_clp" => 0, "description" => "Hasta 5 alumnos, con comisión", "type" => "commission"],
        ["id" => 2, "name" => "INICIAL 20", "price_clp" => 19990, "description" => "Hasta 20 alumnos, sin comisión", "type" => "fixed"],
        ["id" => 3, "name" => "PRO 40", "price_clp" => 29990, "description" => "Hasta 40 alumnos, sin comisión", "type" => "fixed"],
        ["id" => 4, "name" => "ELITE ILIMITADO", "price_clp" => 49990, "description" => "Alumnos ilimitados, sin comisión", "type" => "fixed"]
    ];
}

echo json_encode($plans);
?>
