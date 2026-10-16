<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");
ini_set('display_errors', 0);
error_reporting(E_ALL);

try {
    require_once __DIR__ . "/../db.php";
    require_once __DIR__ . "/../notifications/fcm_sender.php";
    require_once __DIR__ . "/../notifications/notificaciones_helper.php";

    $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 3;

    // Consultar información del usuario (sin seleccionar 'email' para compatibilidad)
    $stmtUser = $conn->prepare("SELECT id, nombre FROM usuarios WHERE id = ?");
    $stmtUser->bind_param('i', $userId);
    $stmtUser->execute();
    $resUser = $stmtUser->get_result();
    $userData = $resUser ? $resUser->fetch_assoc() : null;
    $stmtUser->close();

    if (!$userData) {
        echo json_encode([
            "status" => "error", 
            "message" => "El usuario con ID $userId no fue encontrado en la base de datos."
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Consultar tokens FCM del usuario en la tabla fcm_tokens
    $tokens = [];
    $stmtToken = $conn->prepare("SELECT token FROM fcm_tokens WHERE user_id = ?");
    if ($stmtToken) {
        $stmtToken->bind_param('i', $userId);
        $stmtToken->execute();
        $resTokens = $stmtToken->get_result();
        if ($resTokens) {
            while ($row = $resTokens->fetch_assoc()) {
                if (!empty($row['token'])) {
                    $tokens[] = $row['token'];
                }
            }
        }
        $stmtToken->close();
    }

    // Fallback: revisar columna fcm_token en usuarios si existe
    $resCol = $conn->query("SHOW COLUMNS FROM usuarios LIKE 'fcm_token'");
    if ($resCol && $resCol->num_rows > 0) {
        $stmtUserToken = $conn->prepare("SELECT fcm_token FROM usuarios WHERE id = ?");
        if ($stmtUserToken) {
            $stmtUserToken->bind_param('i', $userId);
            $stmtUserToken->execute();
            $resUT = $stmtUserToken->get_result();
            if ($resUT && $rowUT = $resUT->fetch_assoc()) {
                if (!empty($rowUT['fcm_token']) && !in_array($rowUT['fcm_token'], $tokens)) {
                    $tokens[] = $rowUT['fcm_token'];
                }
            }
            $stmtUserToken->close();
        }
    }

    $titulo = "🎾 Prueba de Notificación PadelBlox";
    $mensaje = "¡Hola " . $userData['nombre'] . "! Esta es una notificación de prueba para verificar tu dispositivo.";

    $pushCount = 0;
    if (!empty($tokens)) {
        $pushCount = send_fcm_push($tokens, $titulo, $mensaje, ['type' => 'test']);
    }

    // Guardar en la tabla de notificaciones de la BD
    notifyUser($conn, $userId, $titulo, $mensaje, 'test');

    echo json_encode([
        "status" => "success",
        "usuario" => [
            "id" => (int)$userData['id'],
            "nombre" => $userData['nombre'],
            "tokens_dispositivos" => count($tokens)
        ],
        "notificaciones_push_exitosas" => $pushCount,
        "diagnostico" => $pushCount > 0 
            ? "¡Excelente! Se envió la notificación Push a $pushCount dispositivo(s) del usuario $userId." 
            : "La notificación se guardó en la base de datos, pero el usuario ID $userId NO tiene ningún token FCM registrado en su celular/navegador."
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode([
        "status" => "error",
        "message" => "Error de ejecución: " . $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
?>
