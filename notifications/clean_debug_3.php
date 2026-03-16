<?php
require_once '../db.php';
header('Content-Type: application/json');

$userId = 3; 

// 1. Borrar tokens de fcm_tokens
$sql1 = "DELETE FROM fcm_tokens WHERE user_id = ?";
$stmt1 = $conn->prepare($sql1);
$stmt1.bind_param("i", $userId);
$res1 = $stmt1->execute();

// 2. Opcionalmente marcar notificaciones como leídas para limpiar la bandeja
$sql2 = "UPDATE notificaciones SET leida = 1 WHERE user_id = ?";
$stmt2 = $conn->prepare($sql2);
$stmt2.bind_param("i", $userId);
$res2 = $stmt2->execute();

echo json_encode([
    "success" => true,
    "user_id" => $userId,
    "tokens_cleared" => $res1,
    "notifications_muted" => $res2,
    "message" => "Listo. Ahora cierra sesión en la App, vuelve a entrar y prueba el link de test.",
    "debug_tip" => "Si usas Xcode, revisa que el bundle ID coincida con el de Firebase."
], JSON_PRETTY_PRINT);
