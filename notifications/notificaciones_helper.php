<?php
require_once __DIR__ . '/fcm_sender.php';

if (!function_exists('notifyUser')) {
    function notifyUser($conn, $userId, $titulo, $mensaje, $tipo = 'general', $fecha_referencia = null, $extraData = []) {
        $userId = intval($userId);
        if ($userId <= 0) return false;

        // 1. Save to database with fallback if prepare fails
        try {
            $stmt = $conn->prepare("INSERT INTO notificaciones (user_id, titulo, mensaje, tipo, fecha_referencia, leida) VALUES (?, ?, ?, ?, ?, 0)");
            if ($stmt) {
                $stmt->bind_param('issss', $userId, $titulo, $mensaje, $tipo, $fecha_referencia);
                $stmt->execute();
                $stmt->close();
            } else {
                throw new Exception("Prepare failed");
            }
        } catch (Throwable $e) {
            // Fallback for tables without fecha_referencia column
            try {
                $stmtFallback = $conn->prepare("INSERT INTO notificaciones (user_id, titulo, mensaje, tipo, leida) VALUES (?, ?, ?, ?, 0)");
                if ($stmtFallback) {
                    $stmtFallback->bind_param('isss', $userId, $titulo, $mensaje, $tipo);
                    $stmtFallback->execute();
                    $stmtFallback->close();
                }
            } catch (Throwable $e2) {
                error_log("notifyUser SQL Prepare Error: " . $e2->getMessage());
            }
        }

        // 2. Send push notification (FCM)
        // Fetch ALL tokens for this user
        $tokens = [];
        try {
            $stmtToken = $conn->prepare("SELECT token FROM fcm_tokens WHERE user_id = ?");
            if ($stmtToken) {
                $stmtToken->bind_param('i', $userId);
                $stmtToken->execute();
                $result = $stmtToken->get_result();
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        if (!empty($row['token']) && !in_array($row['token'], $tokens)) {
                            $tokens[] = $row['token'];
                        }
                    }
                }
                $stmtToken->close();
            }
        } catch (Throwable $e) {
            error_log("notifyUser FCM Token Fetch Error: " . $e->getMessage());
        }

        // FALLBACK: Check users table if no tokens found in fcm_tokens
        if (empty($tokens)) {
            try {
                $stmtUser = $conn->prepare("SELECT fcm_token FROM usuarios WHERE id = ?");
                if ($stmtUser) {
                    $stmtUser->bind_param('i', $userId);
                    $stmtUser->execute();
                    $resUser = $stmtUser->get_result();
                    if ($resUser && $rowUser = $resUser->fetch_assoc()) {
                        if (!empty($rowUser['fcm_token'])) {
                            $tokens[] = $rowUser['fcm_token'];
                        }
                    }
                    $stmtUser->close();
                }
            } catch (Throwable $e) {
                // Ignore if fcm_token column does not exist in usuarios table
            }
        }

        if (!empty($tokens)) {
            $fcmData = array_merge([
                'type' => $tipo,
                'userId' => (string)$userId,
                'timestamp' => (string)time()
            ], $extraData);

            $success = send_fcm_push($tokens, $titulo, $mensaje, $fcmData);
            $logMsg = date('Y-m-d H:i:s') . " - NotifyUser: User $userId - Type: $tipo - Tokens: " . count($tokens) . " - Success: $success" . PHP_EOL;
            file_put_contents(__DIR__ . '/notify_user.log', $logMsg, FILE_APPEND);
        } else {
            $logMsg = date('Y-m-d H:i:s') . " - NotifyUser: User $userId - Type: $tipo - NO TOKENS FOUND" . PHP_EOL;
            file_put_contents(__DIR__ . '/notify_user.log', $logMsg, FILE_APPEND);
        }

        return true;
    }
}

if (!function_exists('notifyMatchReminder2H')) {
    function notifyMatchReminder2H($conn, $userId, $titulo, $mensaje, $tipo = 'recordatorio_2h', $fechaRef = null, $extraData = []) {
        $extraDataDefault = [
            'action' => 'open_match',
            'channel' => 'match_reminders',
            'reminder_lead_hours' => '2'
        ];
        $mergedData = array_merge($extraDataDefault, $extraData);
        return notifyUser($conn, $userId, $titulo, $mensaje, $tipo, $fechaRef, $mergedData);
    }
}
?>
