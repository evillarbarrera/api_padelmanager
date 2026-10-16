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
    // Check if wallet exists
    $stmt = $pdo->prepare("SELECT * FROM wallets WHERE user_id = ?");
    $stmt->execute([$userId]);
    $wallet = $stmt->fetch();

    if (!$wallet) {
        // Create wallet with welcome points (500 pts)
        $pdo->beginTransaction();
        
        $insertWallet = $pdo->prepare("INSERT INTO wallets (user_id, total_balance, version) VALUES (?, ?, 0)");
        $insertWallet->execute([$userId, 500]);
        $walletId = $pdo->lastInsertId();
        
        // Register points transaction
        $insertTx = $pdo->prepare("INSERT INTO points_transactions (wallet_id, transaction_type, amount_points, status, description) VALUES (?, 'adjustment', 500, 'completed', 'Bono de Bienvenida PadelBlox')");
        $insertTx->execute([$walletId]);
        
        $pdo->commit();
        
        // Fetch new wallet
        $stmt->execute([$userId]);
        $wallet = $stmt->fetch();
    }

    // Fetch balances by club
    $clubBalances = [];
    try {
        $clubStmt = $pdo->prepare("
            SELECT 
                c.id AS club_id,
                c.nombre AS club_name,
                c.logo AS club_logo,
                COALESCE(wbc.balance, 0) AS balance
            FROM clubes c
            LEFT JOIN wallet_balances_by_club wbc ON wbc.club_id = c.id AND wbc.wallet_id = ?
            WHERE COALESCE(wbc.balance, 0) > 0
               OR c.id IN (
                   SELECT ca.club_id 
                   FROM reservas_cancha rc 
                   JOIN canchas ca ON rc.cancha_id = ca.id 
                   WHERE rc.usuario_id = ?
               )
               OR c.id IN (
                   SELECT ta.club_id 
                   FROM torneo_participantes tp 
                   JOIN torneos_americanos ta ON tp.torneo_id = ta.id 
                   WHERE tp.usuario_id = ?
               )
            GROUP BY c.id
        ");
        $clubStmt->execute([$wallet['id'], $userId, $userId]);
        $clubBalances = $clubStmt->fetchAll();
        
        // Convert to proper types
        foreach ($clubBalances as &$cb) {
            $cb['club_id'] = intval($cb['club_id']);
            $cb['balance'] = intval($cb['balance']);
        }
    } catch (Exception $e) {
        // Fallback to empty array
    }

    $sumClubBalances = 0;
    foreach ($clubBalances as $cb) {
        $sumClubBalances += $cb['balance'];
    }
    
    $diff = intval($wallet['total_balance']) - $sumClubBalances;
    if ($diff > 0) {
        array_unshift($clubBalances, [
            "club_id" => 0,
            "club_name" => "Saldo General PadelBlox",
            "club_logo" => "assets/pelota.png",
            "balance" => $diff
        ]);
    }

    echo json_encode([
        "success" => true,
        "wallet" => [
            "id" => intval($wallet['id']),
            "user_id" => intval($wallet['user_id']),
            "total_balance" => intval($wallet['total_balance']),
            "currency_code" => $wallet['currency_code']
        ],
        "balances_by_club" => $clubBalances
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Error del servidor: " . $e->getMessage()]);
}
