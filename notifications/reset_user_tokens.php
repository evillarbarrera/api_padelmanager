<?php
/**
 * PadelBlox - Reset & Clean User FCM Push Notification Tokens
 * 
 * Permite limpiar todos los tokens obsoletos acumulados de múltiples dispositivos
 * y registrar únicamente el dispositivo activo actual.
 * 
 * Uso:
 * https://api.padelmanager.cl/prd/notifications/reset_user_tokens.php?email=e.villarbarrera@gmail.com
 * https://api.padelmanager.cl/prd/notifications/reset_user_tokens.php?user_id=XX&send_test=1
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/fcm_sender.php";
require_once __DIR__ . "/notificaciones_helper.php";

$email = trim($_GET['email'] ?? $_POST['email'] ?? '');
$userId = intval($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
$newToken = trim($_GET['new_token'] ?? $_POST['new_token'] ?? '');
$sendTest = isset($_GET['send_test']) && ($_GET['send_test'] == '1' || $_GET['send_test'] === 'true');

$response = [
    "success" => false,
    "user" => null,
    "deleted_tokens_count" => 0,
    "registered_new_token" => false,
    "test_push_sent" => false,
    "message" => ""
];

try {
    // 1. Identificar usuario por email o user_id
    $userRow = null;
    if ($userId > 0) {
        $stmtU = $conn->prepare("SELECT id, nombre, usuario FROM usuarios WHERE id = ? LIMIT 1");
        $stmtU->bind_param('i', $userId);
        $stmtU->execute();
        $resU = $stmtU->get_result();
        $userRow = $resU ? $resU->fetch_assoc() : null;
        $stmtU->close();
    } elseif (!empty($email)) {
        // En la tabla usuarios, el campo 'usuario' almacena el correo
        $stmtU = $conn->prepare("SELECT id, nombre, usuario FROM usuarios WHERE usuario = ? LIMIT 1");
        $stmtU->bind_param('s', $email);
        $stmtU->execute();
        $resU = $stmtU->get_result();
        $userRow = $resU ? $resU->fetch_assoc() : null;
        $stmtU->close();
    } else {
        throw new Exception("Debes especificar un 'email' (ej: e.villarbarrera@gmail.com) o 'user_id'");
    }

    if (!$userRow) {
        throw new Exception("Usuario no encontrado con los datos proporcionados.");
    }

    $targetUserId = intval($userRow['id']);
    $response["user"] = [
        "id" => $targetUserId,
        "nombre" => trim($userRow['nombre'] ?? ''),
        "email" => $userRow['usuario'] ?? ''
    ];

    // 2. Contar tokens existentes antes de borrar
    $checkTokens = $conn->query("SHOW TABLES LIKE 'fcm_tokens'");
    $prevTokens = [];
    if ($checkTokens && $checkTokens->num_rows > 0) {
        $stmtCount = $conn->prepare("SELECT token FROM fcm_tokens WHERE user_id = ?");
        $stmtCount->bind_param('i', $targetUserId);
        $stmtCount->execute();
        $resCount = $stmtCount->get_result();
        if ($resCount) {
            while ($r = $resCount->fetch_assoc()) {
                $prevTokens[] = $r['token'];
            }
        }
        $stmtCount->close();

        // 3. Eliminar todos los tokens antiguos acumulados
        $stmtDel = $conn->prepare("DELETE FROM fcm_tokens WHERE user_id = ?");
        $stmtDel->bind_param('i', $targetUserId);
        $stmtDel->execute();
        $response["deleted_tokens_count"] = count($prevTokens);
        $stmtDel->close();
    }

    // 4. Limpiar columna fcm_token en tabla usuarios solo si existe
    $checkCol = $conn->query("SHOW COLUMNS FROM usuarios LIKE 'fcm_token'");
    if ($checkCol && $checkCol->num_rows > 0) {
        $conn->query("UPDATE usuarios SET fcm_token = NULL WHERE id = $targetUserId");
    }

    // 5. Si se proporcionó un nuevo token, registrarlo como el único activo
    if (!empty($newToken)) {
        if ($checkTokens && $checkTokens->num_rows > 0) {
            $stmtIns = $conn->prepare("INSERT INTO fcm_tokens (user_id, token, created_at) VALUES (?, ?, NOW())");
            $stmtIns->bind_param('is', $targetUserId, $newToken);
            $stmtIns->execute();
            $stmtIns->close();
        }
        if ($checkCol && $checkCol->num_rows > 0) {
            $conn->query("UPDATE usuarios SET fcm_token = '" . $conn->real_escape_string($newToken) . "' WHERE id = $targetUserId");
        }
        $response["registered_new_token"] = true;
    }

    // 6. Si se solicitó test push
    if ($sendTest) {
        $titulo = "🎾 ¡PadelBlox Conectado!";
        $mensaje = "Tu dispositivo ha sido registrado con éxito. Las notificaciones de tus partidos y 2h antes llegarán a este celular.";
        $testResult = notifyUser($conn, $targetUserId, $titulo, $mensaje, 'test_push_reset', date('Y-m-d H:i:s'), [
            'type' => 'test_reset',
            'route' => '/jugador-partidos'
        ]);
        $response["test_push_sent"] = $testResult;
    }

    $response["success"] = true;
    $response["message"] = "Se eliminaron {$response['deleted_tokens_count']} tokens obsoletos del usuario {$response['user']['nombre']} (ID: $targetUserId). Al abrir la app en tu celular actual, se registrará como el único dispositivo receptor.";

    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    $response["success"] = false;
    $response["error"] = $e->getMessage();
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
?>
