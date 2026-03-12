<?php
require_once __DIR__ . '/fcm_sender.php';

function notifyUser($conn, $userId, $titulo, $mensaje, $tipo = 'general', $fecha_referencia = null) {
    $userId = intval($userId);
    if ($userId <= 0) return false;

    // Save to database
    $stmt = $conn->prepare("INSERT INTO notificaciones (user_id, titulo, mensaje, tipo, fecha_referencia, leida) VALUES (?, ?, ?, ?, ?, 0)");
    $stmt->bind_param('issss', $userId, $titulo, $mensaje, $tipo, $fecha_referencia);
    $stmt->execute();
    $stmt->close();

    // Send push notification (FCM)
    // Fetch ALL tokens for this user
    $stmtToken = $conn->prepare("SELECT token FROM fcm_tokens WHERE user_id = ?");
    if ($stmtToken) {
        $stmtToken->bind_param('i', $userId);
        $stmtToken->execute();
        $result = $stmtToken->get_result();
        
        $tokens = [];
        while ($row = $result->fetch_assoc()) {
            if (!empty($row['token'])) {
                $tokens[] = $row['token'];
            }
        }
        $stmtToken->close();

        if (!empty($tokens)) {
            $success = send_fcm_push($tokens, $titulo, $mensaje, ['type' => $tipo]);
            // Log for debugging
            $logMsg = date('Y-m-d H:i:s') . " - NotifyUser: User $userId - Type: $tipo - Tokens: " . count($tokens) . " - Success: $success" . PHP_EOL;
            file_put_contents(__DIR__ . '/notify_user.log', $logMsg, FILE_APPEND);
        } else {
            // Log that we have no tokens
            $logMsg = date('Y-m-d H:i:s') . " - NotifyUser: User $userId - Type: $tipo - NO TOKENS FOUND" . PHP_EOL;
            file_put_contents(__DIR__ . '/notify_user.log', $logMsg, FILE_APPEND);
        }
    }
    return true;
}
?>
