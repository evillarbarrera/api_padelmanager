<?php
require_once '../db.php';
header('Content-Type: application/json');

$userId = 3; // Limpiar tokens del usuario 3

$sql = "DELETE FROM fcm_tokens WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);

if ($stmt->execute()) {
    echo json_encode([
        "success" => true,
        "message" => "Tokens eliminados para el usuario $userId. Por favor, reinicia sesión en la App.",
        "timestamp" => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
} else {
    echo json_encode([
        "success" => false,
        "error" => $conn->error
    ], JSON_PRETTY_PRINT);
}
