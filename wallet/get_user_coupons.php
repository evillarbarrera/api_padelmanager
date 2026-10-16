<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");
header("Access-Control-Allow-Methods: GET");
header("Content-Type: application/json; charset=UTF-8");

require_once "../db.php";

$userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "ID de usuario inválido."]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            rc.id, 
            rc.reward_name, 
            rc.points_cost, 
            rc.coupon_code, 
            rc.status, 
            rc.created_at,
            rc.club_id,
            COALESCE(c.nombre, 'Saldo General') as club_name
        FROM redeemed_coupons rc
        LEFT JOIN clubes c ON rc.club_id = c.id
        WHERE rc.user_id = ? 
        ORDER BY rc.created_at DESC
    ");
    $stmt->execute([$userId]);
    $coupons = $stmt->fetchAll();

    // Cast types
    foreach ($coupons as &$coupon) {
        $coupon['id'] = intval($coupon['id']);
        $coupon['points_cost'] = intval($coupon['points_cost']);
        $coupon['club_id'] = $coupon['club_id'] !== null ? intval($coupon['club_id']) : null;
    }

    echo json_encode([
        "success" => true,
        "coupons" => $coupons
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Error del servidor: " . $e->getMessage()]);
}
