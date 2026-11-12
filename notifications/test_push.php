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

    $tipoPrueba = $_GET['tipo'] ?? 'general';

    if ($tipoPrueba === 'recordatorio') {
        $title = "⏰ ¡Tu partido en Pista Central es en 2 horas!";
        $body = "Hoy a las 19:30 hrs en PowerPadel Machalí. ¡Prepárate con tiempo y activa tu Apple Watch!";
    } elseif ($tipoPrueba === 'tip') {
        $title = "🥎 Consejo PadelBlox: Volea de Bloqueo";
        $body = "Hola " . ($userName ?: 'Jugador') . ", no hagas backswing en la red. Deja que la potencia del rival rebote en tu pala firme.";
    } else {
        $title = $_GET['title'] ?? "🎾 ¡Prueba PadelBlox!";
        $body = $_GET['body'] ?? "Hola " . ($userName ?: 'Jugador') . ", tus notificaciones en PadelBlox están funcionando correctamente.";
    }

    $response["tipo"] = $tipoPrueba;
    $response["title"] = $title;
    $response["body"] = $body;

    if (empty($tokens)) {
        $response["success"] = false;
        $response["message"] = "El usuario '$userEmail' (ID: $targetUserId) no tiene tokens de dispositivos registrados en este momento. Abre la app en tu celular para registrar tu dispositivo actual.";
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Opción para limpiar tokens viejos acumulados si se solicita
    if (isset($_GET['clean']) && $_GET['clean'] == '1') {
        $conn->query("DELETE FROM fcm_tokens WHERE user_id = $targetUserId");
        $response["message"] = "Se limpiaron los " . count($tokens) . " tokens anteriores del usuario. Ahora abre la app en tu celular para registrar únicamente el token actual.";
        $response["tokens_found"] = 0;
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. Probar autenticación con Google Firebase FCM v1
    $diagOAuthError = '';
    $accessToken = get_fcm_access_token($diagOAuthError);

    // Obtener datos de la Service Account para diagnóstico y envío
    $keyData = null;
    $keySource = '';
    if (file_exists(__DIR__ . '/firebase_credentials.php')) {
        require_once __DIR__ . '/firebase_credentials.php';
        if (function_exists('get_firebase_service_account_data')) {
            $keyData = get_firebase_service_account_data();
            $keySource = 'firebase_credentials.php (Base64)';
        }
    }
    if (!$keyData) {
        $keyFile = __DIR__ . '/../academia-padel-firebase-adminsdk-fbsvc-e982fff4f3.json';
        if (!file_exists($keyFile)) {
            $matches = glob(__DIR__ . '/../*firebase-adminsdk*.json');
            if (!empty($matches)) {
                $keyFile = $matches[0];
            }
        }
        if (file_exists($keyFile)) {
            $keyData = json_decode(file_get_contents($keyFile), true);
            $keySource = basename($keyFile);
        }
    }

    $currentKid = $keyData['private_key_id'] ?? 'N/A';
    $clientEmail = $keyData['client_email'] ?? 'N/A';
    $projectId = $keyData['project_id'] ?? 'academia-padel';

    if (!$accessToken) {
        // Consultar a Google las claves públicas vigentes para esta Service Account
        $googleActiveKeys = [];
        $keyStatusMsg = "No se pudo consultar a Google";
        if (!empty($clientEmail) && $clientEmail !== 'N/A') {
            $certsUrl = "https://www.googleapis.com/robot/v1/metadata/x509/" . urlencode($clientEmail);
            $chCert = curl_init($certsUrl);
            curl_setopt($chCert, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chCert, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($chCert, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($chCert, CURLOPT_TIMEOUT, 5);
            $certRes = curl_exec($chCert);
            curl_close($chCert);
            $certJson = json_decode($certRes, true);
            if (is_array($certJson)) {
                $googleActiveKeys = array_keys($certJson);
                if (in_array($currentKid, $googleActiveKeys)) {
                    $keyStatusMsg = "La clave ID $currentKid está ACTIVA en Google.";
                } else {
                    $keyStatusMsg = "ATENCIÓN: La clave privada local (ID: '$currentKid') fue REVOCADA o ELIMINADA en Google. Claves activas en Google Console: " . implode(", ", $googleActiveKeys);
                }
            } else {
                $keyStatusMsg = "Respuesta de certificados Google: " . substr($certRes, 0, 100);
            }
        }

        $response["success"] = false;
        $response["error"] = "No se pudo obtener el Access Token de Google OAuth2 para FCM.";
        $response["oauth_detail"] = $diagOAuthError;
        $response["server_time_utc"] = gmdate('Y-m-d H:i:s');
        $response["server_time_local"] = date('Y-m-d H:i:s T');
        $response["service_account"] = [
            "origen_credenciales" => $keySource ?: "No se encontró ningún archivo de credenciales",
            "client_email" => $clientEmail,
            "private_key_id_actual" => $currentKid,
            "estado_en_google" => $keyStatusMsg,
            "claves_activas_en_google" => $googleActiveKeys
        ];
        $response["diagnostics"][] = "Origen de credenciales: " . ($keySource ?: "NO ENCONTRADAS");
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    $response["diagnostics"][] = "Google OAuth2 Access Token obtenido correctamente.";

    // 4. Enviar notificación push inspeccionando la respuesta de Google para cada token
    $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ];

    $fcmResults = [];
    $successCount = 0;
    $invalidTokens = [];

    foreach ($tokens as $token) {
        $message = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body
                ],
                'data' => [
                    'type' => 'test',
                    'route' => '/jugador-partidos',
                    'timestamp' => (string)time()
                ],
                'android' => [
                    'priority' => 'HIGH',
                    'notification' => [
                        'sound' => 'default',
                        'default_sound' => true,
                        'default_vibrate_timings' => true,
                        'notification_priority' => 'PRIORITY_HIGH'
                    ]
                ],
                'apns' => [
                    'headers' => [
                        'apns-priority' => '10',
                        'apns-push-type' => 'alert'
                    ],
                    'payload' => [
                        'aps' => [
                            'alert' => [
                                'title' => $title,
                                'body' => $body
                            ],
                            'sound' => 'default',
                            'badge' => 1
                        ]
                    ]
                ]
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $resCurl = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $parsedRes = json_decode($resCurl, true);

        if ($httpCode === 200) {
            $successCount++;
            $fcmResults[] = [
                "token" => substr($token, 0, 15) . "...",
                "status" => "DELIVERED_TO_GOOGLE_FCM",
                "http_code" => $httpCode,
                "fcm_message_id" => $parsedRes['name'] ?? 'OK'
            ];
        } else {
            $errCode = $parsedRes['error']['details'][0]['errorCode'] ?? ($parsedRes['error']['status'] ?? 'ERROR');
            $errMsg = $parsedRes['error']['message'] ?? $resCurl;
            $fcmResults[] = [
                "token" => substr($token, 0, 15) . "...",
                "status" => "REJECTED_BY_GOOGLE",
                "http_code" => $httpCode,
                "error_code" => $errCode,
                "error_message" => $errMsg
            ];
            if ($httpCode === 404 || $errCode === 'UNREGISTERED') {
                $invalidTokens[] = $token;
            }
        }
    }

    // Auto-limpiar tokens declarados como UNREGISTERED por Google si fallaron todos o parte
    if (!empty($invalidTokens) && isset($_GET['auto_prune'])) {
        foreach ($invalidTokens as $invT) {
            $conn->query("DELETE FROM fcm_tokens WHERE token = '$invT'");
        }
        $response["diagnostics"][] = "Se eliminaron " . count($invalidTokens) . " tokens obsoletos de Google.";
    }

    // Registrar en tabla notificaciones internas (solo base de datos, sin re-enviar push duplicado)
    try {
        $nowStr = date('Y-m-d H:i:s');
        $stDb = $conn->prepare("INSERT INTO notificaciones (user_id, titulo, mensaje, tipo, fecha_referencia, leida) VALUES (?, ?, ?, 'test_push', ?, 0)");
        if ($stDb) {
            $stDb->bind_param('isss', $targetUserId, $title, $body, $nowStr);
            $stDb->execute();
            $stDb->close();
        } else {
            $stDb2 = $conn->prepare("INSERT INTO notificaciones (user_id, titulo, mensaje, tipo, leida) VALUES (?, ?, ?, 'test_push', 0)");
            if ($stDb2) {
                $stDb2->bind_param('iss', $targetUserId, $title, $body);
                $stDb2->execute();
                $stDb2->close();
            }
        }
    } catch (Throwable $ignoreDb) {}

    $response["success"] = ($successCount > 0);
    $response["delivered_count"] = $successCount;
    $response["failed_count"] = count($tokens) - $successCount;
    $response["fcm_details"] = $fcmResults;
    $response["message"] = ($successCount > 0)
        ? "Google FCM aceptó la entrega para $successCount dispositivo(s)."
        : "Ningún token fue aceptado por Google FCM. Todos fueron rechazados (dispositivos desinstalados, tokens viejos o certificados de Apple pendientes).";

    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    $response["success"] = false;
    $response["error"] = $e->getMessage();
    $response["file"] = basename($e->getFile()) . ':' . $e->getLine();
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
?>
