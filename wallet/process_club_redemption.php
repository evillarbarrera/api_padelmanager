<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once "../db.php";

$input = json_decode(file_get_contents("php://input"), true);
if (!$input) {
    $input = $_POST;
}

$couponCode = isset($input['coupon_code']) ? trim($input['coupon_code']) : '';
$action = isset($input['action']) ? trim($input['action']) : 'verify'; // 'verify' or 'confirm'

if (empty($couponCode)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Código de cupón inválido o ausente."]);
    exit;
}

try {
    // Search coupon and fetch player details as well
    $stmt = $pdo->prepare("
        SELECT rc.*, u.nombre as player_name 
        FROM redeemed_coupons rc
        JOIN users u ON rc.user_id = u.id
        WHERE rc.coupon_code = ?
    ");
    $stmt->execute([$couponCode]);
    $coupon = $stmt->fetch();
    
    if (!$coupon) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Cupón no encontrado. Verifica el código."]);
        exit;
    }
    
    if ($action === 'verify') {
        echo json_encode([
            "success" => true,
            "coupon" => [
                "id" => intval($coupon['id']),
                "player_name" => $coupon['player_name'],
                "reward_name" => $coupon['reward_name'],
                "points_cost" => intval($coupon['points_cost']),
                "coupon_code" => $coupon['coupon_code'],
                "status" => $coupon['status'],
                "created_at" => $coupon['created_at']
            ]
        ]);
        exit;
    }
    
    if ($action === 'confirm') {
        if ($coupon['status'] !== 'pending') {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Este cupón ya fue utilizado o se encuentra expirado."]);
            exit;
        }
        
        // Update coupon status to used
        $updateStmt = $pdo->prepare("UPDATE redeemed_coupons SET status = 'used' WHERE id = ?");
        $updateStmt->execute([$coupon['id']]);
        
        echo json_encode([
            "success" => true,
            "message" => "Canje verificado y cupón entregado con éxito.",
            "player_name" => $coupon['player_name'],
            "reward_name" => $coupon['reward_name']
        ]);
        exit;
    }
    
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Acción inválida."]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Error al procesar el cupón: " . $e->getMessage()]);
}
