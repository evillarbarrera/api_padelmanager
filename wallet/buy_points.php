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
$packageId = isset($input['package_id']) ? intval($input['package_id']) : 0;
$clubId = isset($input['club_id']) ? intval($input['club_id']) : 0;

if ($userId <= 0 || $packageId <= 0) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "ID de usuario o paquete inválido."]);
    exit;
}

// Define points packages
$packages = [
    1 => ["points" => 1000, "price" => 10000, "name" => "Pack Bronce (1,000 pts)"],
    2 => ["points" => 2500, "price" => 22000, "name" => "Pack Plata (2,500 pts)"],
    3 => ["points" => 5000, "price" => 40000, "name" => "Pack Oro (5,000 pts)"]
];

if (!isset($packages[$packageId])) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Paquete de puntos seleccionado no existe."]);
    exit;
}

$pack = $packages[$packageId];
$pointsToAdd = $pack['points'];
$packName = $pack['name'];

try {
    $pdo->beginTransaction();
    
    // Check if wallet exists, lock the row for update (ACID integrity)
    $stmt = $pdo->prepare("SELECT * FROM wallets WHERE user_id = ? FOR UPDATE");
    $stmt->execute([$userId]);
    $wallet = $stmt->fetch();
    
    if (!$wallet) {
        // Create new wallet
        $insertWallet = $pdo->prepare("INSERT INTO wallets (user_id, total_balance, version) VALUES (?, ?, 0)");
        $insertWallet->execute([$userId, $pointsToAdd]);
        $walletId = $pdo->lastInsertId();
    } else {
        $walletId = $wallet['id'];
        // Update balance and version in wallets
        $updateWallet = $pdo->prepare("UPDATE wallets SET total_balance = total_balance + ?, version = version + 1 WHERE id = ?");
        $updateWallet->execute([$pointsToAdd, $walletId]);
    }
    
    // Update balance in wallet_balances_by_club
    if ($clubId > 0) {
        $updateClubBalance = $pdo->prepare("
            INSERT INTO wallet_balances_by_club (wallet_id, club_id, balance) 
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE balance = balance + ?
        ");
        $updateClubBalance->execute([$walletId, $clubId, $pointsToAdd, $pointsToAdd]);
    }
    
    // Register transaction log with club_id
    $insertTx = $pdo->prepare("INSERT INTO points_transactions (wallet_id, club_id, transaction_type, amount_points, reference_type, reference_id, status, description) VALUES (?, ?, 'purchase', ?, 'payments', ?, 'completed', ?)");
    $insertTx->execute([$walletId, $clubId > 0 ? $clubId : null, $pointsToAdd, $packageId, "Compra de Puntos: " . $packName]);
    
    $pdo->commit();
    
    // Fetch final balance
    $finalStmt = $pdo->prepare("SELECT total_balance FROM wallets WHERE id = ?");
    $finalStmt->execute([$walletId]);
    $finalWallet = $finalStmt->fetch();
    
    echo json_encode([
        "success" => true,
        "message" => "Compra procesada exitosamente.",
        "new_balance" => intval($finalWallet['total_balance']),
        "added_points" => $pointsToAdd
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Error al procesar la compra: " . $e->getMessage()]);
}
