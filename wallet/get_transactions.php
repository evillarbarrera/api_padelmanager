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
    // Get user wallet ID
    $stmt = $pdo->prepare("SELECT id FROM wallets WHERE user_id = ?");
    $stmt->execute([$userId]);
    $wallet = $stmt->fetch();

    if (!$wallet) {
        // Return empty list if no wallet exists yet
        echo json_encode(["success" => true, "transactions" => []]);
        exit;
    }

    $walletId = $wallet['id'];

    // Get transactions
    $txStmt = $pdo->prepare("SELECT id, club_id, transaction_type, amount_points, reference_type, reference_id, status, description, created_at FROM points_transactions WHERE wallet_id = ? ORDER BY created_at DESC");
    $txStmt->execute([$walletId]);
    $transactions = $txStmt->fetchAll();

    // Map amounts and types
    $result = [];
    foreach ($transactions as $tx) {
        $result[] = [
            "id" => intval($tx['id']),
            "club_id" => $tx['club_id'] ? intval($tx['club_id']) : null,
            "transaction_type" => $tx['transaction_type'],
            "amount_points" => intval($tx['amount_points']),
            "reference_type" => $tx['reference_type'],
            "reference_id" => $tx['reference_id'] ? intval($tx['reference_id']) : null,
            "status" => $tx['status'],
            "description" => $tx['description'],
            "created_at" => $tx['created_at']
        ];
    }

    echo json_encode(["success" => true, "transactions" => $result]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Error del servidor: " . $e->getMessage()]);
}
