<?php
/**
 * PadelBlox - Comprehensive Notification & FCM Tokens Audit Script
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../db.php';

$report = [
    "generated_at" => date('Y-m-d H:i:s'),
    "total_usuarios" => 0,
    "usuarios_con_tokens" => 0,
    "usuarios_sin_tokens" => 0,
    "total_tokens_registrados" => 0,
    "resumen_usuarios" => [],
    "ultimos_logs_envio" => [],
    "ultimos_errores_fcm" => []
];

try {
    // 1. Contar total de usuarios
    $resTot = $conn->query("SELECT COUNT(*) as c FROM usuarios");
    if ($resTot && $rTot = $resTot->fetch_assoc()) {
        $report["total_usuarios"] = intval($rTot['c']);
    }

    // 2. Obtener lista de usuarios y sus tokens
    $sql = "SELECT u.id, u.nombre, u.usuario as email, u.rol,
                   GROUP_CONCAT(DISTINCT f.token SEPARATOR '|||') as tokens,
                   GROUP_CONCAT(DISTINCT f.created_at SEPARATOR '|||') as token_dates,
                   COUNT(f.id) as token_count,
                   MAX(f.created_at) as ultimo_token_fecha
            FROM usuarios u
            LEFT JOIN fcm_tokens f ON u.id = f.user_id
            GROUP BY u.id, u.nombre, u.usuario, u.rol
            ORDER BY ultimo_token_fecha DESC, u.id ASC";

    $res = $conn->query($sql);
    $conTokens = 0;
    $sinTokens = 0;
    $totalTokens = 0;

    while ($row = $res->fetch_assoc()) {
        $uId = intval($row['id']);
        $tCount = intval($row['token_count']);
        $hasToken = $tCount > 0;

        if ($hasToken) {
            $conTokens++;
            $totalTokens += $tCount;
        } else {
            $sinTokens++;
        }

        $tokenSample = '';
        if (!empty($row['tokens'])) {
            $tList = explode('|||', $row['tokens']);
            $first = $tList[0];
            $tokenSample = substr($first, 0, 14) . '...' . substr($first, -6);
        }

        $report["resumen_usuarios"][] = [
            "id" => $uId,
            "nombre" => $row['nombre'] ?? 'Sin Nombre',
            "email" => $row['email'] ?? '',
            "rol" => $row['rol'] ?? 'jugador',
            "tiene_token" => $hasToken,
            "cantidad_tokens" => $tCount,
            "ultimo_token_fecha" => $row['ultimo_token_fecha'] ?? null,
            "token_sample" => $tokenSample
        ];
    }

    $report["usuarios_con_tokens"] = $conTokens;
    $report["usuarios_sin_tokens"] = $sinTokens;
    $report["total_tokens_registrados"] = $totalTokens;

    // 3. Leer los últimos logs de envío
    $logFile = __DIR__ . '/notify_user.log';
    if (file_exists($logFile)) {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines) {
            $lastLines = array_slice($lines, -25);
            $report["ultimos_logs_envio"] = array_reverse($lastLines);
        }
    }

    // 4. Leer los últimos errores de FCM
    $errFile = __DIR__ . '/fcm_errors.log';
    if (file_exists($errFile)) {
        $errLines = file($errFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($errLines) {
            $lastErr = array_slice($errLines, -25);
            $report["ultimos_errores_fcm"] = array_reverse($lastErr);
        }
    }

    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        "error" => $e->getMessage(),
        "file" => basename($e->getFile()) . ':' . $e->getLine()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
?>
