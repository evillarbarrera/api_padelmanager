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

$userId = isset($input['user_id']) ? intval($input['user_id']) : 0;
$rewardId = isset($input['reward_id']) ? intval($input['reward_id']) : 0;
$clubId = isset($input['club_id']) ? intval($input['club_id']) : 0; // 0 means general/welcome points

if ($userId <= 0 || $rewardId <= 0) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "ID de usuario o premio inválido."]);
    exit;
}

// Predefined rewards list
$rewards = [
    1 => ["name" => "Bebida Isotónica", "cost" => 150],
    2 => ["name" => "Tubo de Pelotas Head Pro", "cost" => 400],
    3 => ["name" => "Mochila PadelBlox", "cost" => 1500],
    4 => ["name" => "Pala de Pádel Pro", "cost" => 10000]
];

if (!isset($rewards[$rewardId])) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "El premio seleccionado no existe en el catálogo."]);
    exit;
}

$reward = $rewards[$rewardId];
$rewardName = $reward['name'];
$pointsCost = $reward['cost'];

try {
    $pdo->beginTransaction();
    
    // Check wallet balance
    $stmt = $pdo->prepare("SELECT * FROM wallets WHERE user_id = ? FOR UPDATE");
    $stmt->execute([$userId]);
    $wallet = $stmt->fetch();
    
    if (!$wallet) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Billetera no encontrada para este usuario."]);
        exit;
    }
    
    $walletId = $wallet['id'];
    
    // Check points balance based on clubId
    if ($clubId > 0) {
        $clubBalanceStmt = $pdo->prepare("SELECT balance FROM wallet_balances_by_club WHERE wallet_id = ? AND club_id = ? FOR UPDATE");
        $clubBalanceStmt->execute([$walletId, $clubId]);
        $clubBalance = $clubBalanceStmt->fetchColumn();
        
        if ($clubBalance === false || intval($clubBalance) < $pointsCost) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Puntos insuficientes en este club para realizar el canje."]);
            exit;
        }
        
        // Deduct points from club-specific balance
        $updateClub = $pdo->prepare("UPDATE wallet_balances_by_club SET balance = balance - ? WHERE wallet_id = ? AND club_id = ?");
        $updateClub->execute([$pointsCost, $walletId, $clubId]);
        
    } else {
        // Checking general balance: total_balance - SUM(balances_by_club)
        $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(balance), 0) FROM wallet_balances_by_club WHERE wallet_id = ?");
        $sumStmt->execute([$walletId]);
        $sumClubBalances = intval($sumStmt->fetchColumn());
        
        $generalBalance = intval($wallet['total_balance']) - $sumClubBalances;
        if ($generalBalance < $pointsCost) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Puntos insuficientes en el saldo general para realizar el canje."]);
            exit;
        }
    }
    
    // Deduct points from total balance
    $updateWallet = $pdo->prepare("UPDATE wallets SET total_balance = total_balance - ?, version = version + 1 WHERE id = ?");
    $updateWallet->execute([$pointsCost, $walletId]);
    
    // Generate a unique coupon code (PB-XXXXXX format)
    $couponCode = "PB-" . strtoupper(bin2hex(random_bytes(3))); // generates 6 uppercase letters/numbers
    
    // Ensure coupon code is unique
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM redeemed_coupons WHERE coupon_code = ?");
    $checkStmt->execute([$couponCode]);
    if ($checkStmt->fetchColumn() > 0) {
        $couponCode = "PB-" . strtoupper(bin2hex(random_bytes(3))); // regenerate once
    }
    
    // Insert into redeemed_coupons with club_id
    $insertCoupon = $pdo->prepare("INSERT INTO redeemed_coupons (user_id, club_id, reward_name, points_cost, coupon_code, status) VALUES (?, ?, ?, ?, ?, 'pending')");
    $insertCoupon->execute([$userId, $clubId > 0 ? $clubId : null, $rewardName, $pointsCost, $couponCode]);
    
    // Log transaction with club_id
    $insertTx = $pdo->prepare("INSERT INTO points_transactions (wallet_id, club_id, transaction_type, amount_points, reference_type, reference_id, status, description) VALUES (?, ?, 'redeem', ?, 'rewards', ?, 'completed', ?)");
    $insertTx->execute([$walletId, $clubId > 0 ? $clubId : null, -$pointsCost, $rewardId, "Canje de Cupón: " . $rewardName]);
    
    $pdo->commit();
    
    // Fetch final balance
    $finalStmt = $pdo->prepare("SELECT total_balance FROM wallets WHERE id = ?");
    $finalStmt->execute([$walletId]);
    $finalWallet = $finalStmt->fetch();
    
    echo json_encode([
        "success" => true,
        "message" => "Cupón canjeado exitosamente.",
        "coupon_code" => $couponCode,
        "new_balance" => intval($finalWallet['total_balance']),
        "reward_name" => $rewardName
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Error al procesar el canje de puntos: " . $e->getMessage()]);
}
