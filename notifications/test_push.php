<?php
/**
 * PadelBlox - Test Push Notification Endpoint
 * 
 * Uso:
 * https://api.padelmanager.cl/prd/notifications/test_push.php?email=e.villarbarrera@gmail.com
 * https://api.padelmanager.cl/prd/notifications/test_push.php?user_id=1
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$response = [
    "success" => false,
    "user_id" => null,
    "user_name" => null,
    "email" => null,
    "tokens_found" => 0,
    "push_result" => null,
    "title" => null,
    "body" => null,
    "message" => "",
    "diagnostics" => []
];

try {
    require_once __DIR__ . '/../db.php';
    require_once __DIR__ . '/notificaciones_helper.php';
    require_once __DIR__ . '/fcm_sender.php';

    if (!$conn || $conn->connect_error) {
        throw new Exception("Error de conexión a la base de datos: " . ($conn ? $conn->connect_error : 'No connection'));
    }

    $userId = intval($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
    $email = trim($_GET['email'] ?? $_POST['email'] ?? '');

    $targetUserId = 0;
    $userName = '';
    $userEmail = '';

    // 1. Buscar usuario
    if ($userId > 0) {
        $stmt = $conn->prepare("SELECT id, nombre, usuario FROM usuarios WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $targetUserId = intval($row['id']);
                $userName = trim($row['nombre'] ?? '');
                $userEmail = $row['usuario'] ?? '';
            }
            $stmt->close();
        }
    } elseif (!empty($email)) {
        // En la tabla usuarios, el campo 'usuario' almacena el correo
        $stmt = $conn->prepare("SELECT id, nombre, usuario FROM usuarios WHERE usuario = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $targetUserId = intval($row['id']);
                $userName = trim($row['nombre'] ?? '');
                $userEmail = $row['usuario'] ?? '';
            }
            $stmt->close();
        }
    }

    if ($targetUserId <= 0) {
        $response["message"] = "Usuario no encontrado con identificador '$email' / ID '$userId'.";
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $response["user_id"] = $targetUserId;
    $response["user_name"] = $userName;
    $response["email"] = $userEmail;

    // 2. Comprobar tokens FCM registrados para este usuario
    $tokens = [];
    $checkTbl = $conn->query("SHOW TABLES LIKE 'fcm_tokens'");
    if ($checkTbl && $checkTbl->num_rows > 0) {
        $st = $conn->prepare("SELECT token, created_at FROM fcm_tokens WHERE user_id = ?");
        if ($st) {
            $st->bind_param('i', $targetUserId);
            $st->execute();
            $rTokens = $st->get_result();
            while ($tRow = $rTokens->fetch_assoc()) {
                if (!empty($tRow['token']) && !in_array($tRow['token'], $tokens)) {
                    $tokens[] = $tRow['token'];
                    $response["diagnostics"][] = "Token en fcm_tokens: " . substr($tRow['token'], 0, 15) . "... (creado: " . ($tRow['created_at'] ?? 'N/A') . ")";
                }
            }
            $st->close();
        }
    }

    // Fallback: revisar columna fcm_token en usuarios
    $checkCol = $conn->query("SHOW COLUMNS FROM usuarios LIKE 'fcm_token'");
    if ($checkCol && $checkCol->num_rows > 0) {
        $stU = $conn->prepare("SELECT fcm_token FROM usuarios WHERE id = ?");
        if ($stU) {
            $stU->bind_param('i', $targetUserId);
            $stU->execute();
            $rU = $stU->get_result();
            if ($rU && $rowU = $rU->fetch_assoc()) {
                if (!empty($rowU['fcm_token']) && !in_array($rowU['fcm_token'], $tokens)) {
                    $tokens[] = $rowU['fcm_token'];
                    $response["diagnostics"][] = "Token en tabla usuarios: " . substr($rowU['fcm_token'], 0, 15) . "...";
                }
            }
            $stU->close();
        }
    }

    $response["tokens_found"] = count($tokens);

    $title = $_GET['title'] ?? "🎾 ¡Prueba PadelBlox!";
    $body = $_GET['body'] ?? "Hola " . ($userName ?: 'Jugador') . ", tus notificaciones en PadelBlox están funcionando correctamente.";
    $response["title"] = $title;
    $response["body"] = $body;

    if (empty($tokens)) {
        $response["success"] = false;
        $response["message"] = "El usuario '$userEmail' (ID: $targetUserId) no tiene tokens de dispositivos registrados en este momento. Abre la app en tu celular para registrar tu dispositivo actual.";
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. Enviar notificación push
    $pushSent = notifyUser($conn, $targetUserId, $title, $body, 'test_push', date('Y-m-d H:i:s'), [
        'type' => 'test',
        'route' => '/jugador-partidos'
    ]);

    $response["success"] = true;
    $response["push_result"] = $pushSent;
    $response["message"] = "Notificación de prueba enviada exitosamente a " . count($tokens) . " dispositivo(s) activo(s).";

    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    $response["success"] = false;
    $response["error"] = $e->getMessage();
    $response["file"] = basename($e->getFile()) . ':' . $e->getLine();
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
?>
