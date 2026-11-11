<?php
/**
 * PadelBlox - Desvincular Tokens Compartidos / Duplicados
 * 
 * Uso:
 * https://api.padelmanager.cl/prd/notifications/desvincular_dispositivo.php?email=e.villarbarrera@gmail.com
 * https://api.padelmanager.cl/prd/notifications/desvincular_dispositivo.php?user_id=3
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$response = [
    "success" => false,
    "user_solicitado" => null,
    "tokens_del_usuario" => 0,
    "conflictos_detectados" => [],
    "conflictos_eliminados" => 0,
    "tokens_duplicados_limpiados" => 0,
    "ultimas_notificaciones_sistema" => [],
    "mensaje" => ""
];

try {
    require_once __DIR__ . '/../db.php';

    if (!$conn || $conn->connect_error) {
        throw new Exception("Error de conexión a la base de datos.");
    }

    $userId = intval($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
    $email = trim($_GET['email'] ?? $_POST['email'] ?? '');

    $targetUser = null;

    if ($userId > 0) {
        $stmt = $conn->prepare("SELECT id, nombre, usuario as email, rol FROM usuarios WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $targetUser = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    } elseif (!empty($email)) {
        $stmt = $conn->prepare("SELECT id, nombre, usuario as email, rol FROM usuarios WHERE usuario = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $targetUser = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    }

    if (!$targetUser) {
        throw new Exception("Usuario no encontrado con los parámetros suministrados.");
    }

    $targetUserId = intval($targetUser['id']);
    $response["user_solicitado"] = $targetUser;

    // 1. Obtener los tokens actuales de este usuario
    $userTokens = [];
    $stmtT = $conn->prepare("SELECT id, token, created_at FROM fcm_tokens WHERE user_id = ? ORDER BY id DESC");
    if ($stmtT) {
        $stmtT->bind_param('i', $targetUserId);
        $stmtT->execute();
        $resT = $stmtT->get_result();
        while ($row = $resT->fetch_assoc()) {
            $userTokens[] = $row['token'];
        }
        $stmtT->close();
    }
    $userTokens = array_values(array_unique(array_filter($userTokens)));
    $response["tokens_del_usuario"] = count($userTokens);

    if (empty($userTokens)) {
        $response["mensaje"] = "Tu usuario no tiene tokens registrados actualmente en la base de datos. Abre la app en tu celular para registrarlo.";
    } else {
        // 2. Buscar si esos mismos tokens están asociados a OTROS usuarios (conflictos por login previo)
        $conflicts = [];
        $deletedConflicts = 0;

        foreach ($userTokens as $token) {
            $stmtC = $conn->prepare("
                SELECT f.id as token_id, f.user_id, u.nombre, u.usuario as email, u.rol, f.created_at
                FROM fcm_tokens f
                LEFT JOIN usuarios u ON f.user_id = u.id
                WHERE f.token = ? AND f.user_id != ?
            ");
            if ($stmtC) {
                $stmtC->bind_param('si', $token, $targetUserId);
                $stmtC->execute();
                $resC = $stmtC->get_result();
                while ($cRow = $resC->fetch_assoc()) {
                    $conflicts[] = [
                        "otro_user_id" => intval($cRow['user_id']),
                        "otro_nombre" => $cRow['nombre'] ?? 'Sin Nombre',
                        "otro_email" => $cRow['email'] ?? 'Sin Email',
                        "otro_rol" => $cRow['rol'] ?? 'desconocido',
                        "token_compartido" => substr($token, 0, 15) . '...',
                        "asignado_en" => $cRow['created_at']
                    ];
                }
                $stmtC->close();
            }

            // Desvincular de los otros usuarios
            $stmtDel = $conn->prepare("DELETE FROM fcm_tokens WHERE token = ? AND user_id != ?");
            if ($stmtDel) {
                $stmtDel->bind_param('si', $token, $targetUserId);
                $stmtDel->execute();
                $deletedConflicts += $stmtDel->affected_rows;
                $stmtDel->close();
            }
        }

        $response["conflictos_detectados"] = $conflicts;
        $response["conflictos_eliminados"] = $deletedConflicts;

        // 3. Deduplicar tokens del propio usuario (mantener solo el más reciente)
        $delDupes = 0;
        foreach ($userTokens as $token) {
            // Obtener el ID más alto para este token
            $resMax = $conn->query("SELECT MAX(id) as max_id FROM fcm_tokens WHERE user_id = $targetUserId AND token = '" . $conn->real_escape_string($token) . "'");
            if ($resMax && $rMax = $resMax->fetch_assoc()) {
                $keepId = intval($rMax['max_id']);
                if ($keepId > 0) {
                    $conn->query("DELETE FROM fcm_tokens WHERE user_id = $targetUserId AND token = '" . $conn->real_escape_string($token) . "' AND id != $keepId");
                    $delDupes += $conn->affected_rows;
                }
            }
        }
        $response["tokens_duplicados_limpiados"] = $delDupes;
    }

    // 4. Obtener las últimas 15 notificaciones enviadas en el sistema para entender el origen
    $resNotif = $conn->query("
        SELECT n.id, n.user_id, u.nombre as destinatario_nombre, u.rol as destinatario_rol,
               n.titulo, n.mensaje, n.tipo, n.fecha
        FROM notificaciones n
        LEFT JOIN usuarios u ON n.user_id = u.id
        ORDER BY n.id DESC
        LIMIT 15
    ");
    if ($resNotif) {
        while ($nRow = $resNotif->fetch_assoc()) {
            $response["ultimas_notificaciones_sistema"][] = [
                "id" => intval($nRow['id']),
                "destinatario_id" => intval($nRow['user_id']),
                "destinatario_nombre" => $nRow['destinatario_nombre'] ?? 'N/A',
                "destinatario_rol" => $nRow['destinatario_rol'] ?? 'N/A',
                "titulo" => $nRow['titulo'] ?? '',
                "tipo" => $nRow['tipo'] ?? '',
                "fecha" => $nRow['fecha'] ?? ''
            ];
        }
    }

    $response["success"] = true;
    if ($deletedConflicts > 0) {
        $response["mensaje"] = "¡Listo! Se desvincularon $deletedConflicts cuenta(s) ajenas que compartían el token de tu celular. A partir de ahora solo recibirás las notificaciones dirigidas a ti ($targetUserId - {$targetUser['nombre']}).";
    } else {
        $response["mensaje"] = "Tu dispositivo ya se encuentra correctamente aislado y vinculado únicamente a tu usuario ($targetUserId - {$targetUser['nombre']}).";
    }

    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    $response["success"] = false;
    $response["error"] = $e->getMessage();
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
