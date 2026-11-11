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
    $isGlobal = isset($_GET['global']) && ($_GET['global'] == '1' || $_GET['global'] === 'true');

    // Auto-activar modo global si no se especifica usuario
    if ($userId <= 0 && empty($email)) {
        $isGlobal = true;
    }

    if ($isGlobal) {
        // =====================================================================
        // SANEAMIENTO GLOBAL: Limpiar TODOS los tokens compartidos en el sistema
        // =====================================================================
        $globalConflicts = [];
        $totalEliminados = 0;

        // 1. Detectar tokens presentes en más de un usuario
        $resMulti = $conn->query("
            SELECT token, COUNT(DISTINCT user_id) as total_users, MAX(id) as keep_id
            FROM fcm_tokens
            GROUP BY token
            HAVING total_users > 1
        ");

        if ($resMulti) {
            while ($rM = $resMulti->fetch_assoc()) {
                $cToken = $rM['token'];
                $keepId = intval($rM['keep_id']);
                
                // Obtener detalles de a quiénes afectaba
                $stInf = $conn->prepare("SELECT f.id, f.user_id, u.nombre, u.usuario as email, u.rol FROM fcm_tokens f LEFT JOIN usuarios u ON f.user_id = u.id WHERE f.token = ?");
                $afectados = [];
                if ($stInf) {
                    $stInf->bind_param('s', $cToken);
                    $stInf->execute();
                    $resInf = $stInf->get_result();
                    while ($rowInf = $resInf->fetch_assoc()) {
                        $afectados[] = [
                            "token_id" => intval($rowInf['id']),
                            "user_id" => intval($rowInf['user_id']),
                            "nombre" => $rowInf['nombre'] ?? 'N/A',
                            "email" => $rowInf['email'] ?? 'N/A',
                            "rol" => $rowInf['rol'] ?? 'N/A',
                            "conservado" => (intval($rowInf['id']) === $keepId)
                        ];
                    }
                    $stInf->close();
                }

                $globalConflicts[] = [
                    "token_sample" => substr($cToken, 0, 15) . '...',
                    "total_usuarios" => intval($rM['total_users']),
                    "keep_id" => $keepId,
                    "cuentas_afectadas" => $afectados
                ];

                // Borrar todas las asociaciones de este token excepto el keepId (la sesión más reciente)
                $stDelM = $conn->prepare("DELETE FROM fcm_tokens WHERE token = ? AND id != ?");
                if ($stDelM) {
                    $stDelM->bind_param('si', $cToken, $keepId);
                    $stDelM->execute();
                    $totalEliminados += $stDelM->affected_rows;
                    $stDelM->close();
                }
            }
        }

        // 2. Limpiar duplicados del mismo token para el mismo usuario
        $resSelfDup = $conn->query("
            SELECT user_id, token, MAX(id) as keep_id, COUNT(*) as cnt
            FROM fcm_tokens
            GROUP BY user_id, token
            HAVING cnt > 1
        ");
        $dupesCleaned = 0;
        if ($resSelfDup) {
            while ($rD = $resSelfDup->fetch_assoc()) {
                $uId = intval($rD['user_id']);
                $tStr = $rD['token'];
                $kId = intval($rD['keep_id']);
                $conn->query("DELETE FROM fcm_tokens WHERE user_id = $uId AND token = '" . $conn->real_escape_string($tStr) . "' AND id != $kId");
                $dupesCleaned += $conn->affected_rows;
            }
        }

        // 3. Sincronizar / limpiar usuarios.fcm_token si apunta a un token que ya no le pertenece
        $usuariosColCleaned = 0;
        try {
            $chkCol = $conn->query("SHOW COLUMNS FROM usuarios LIKE 'fcm_token'");
            if ($chkCol && $chkCol->num_rows > 0) {
                // Si el token de usuarios.fcm_token está en fcm_tokens con otro usuario, nulificarlo
                $conn->query("
                    UPDATE usuarios u
                    JOIN fcm_tokens f ON u.fcm_token = f.token AND u.id != f.user_id
                    SET u.fcm_token = NULL
                ");
                $usuariosColCleaned = $conn->affected_rows;
            }
        } catch (Throwable $e) {}

        // 4. Expirar recordatorios programados huérfanos con más de 3 horas de retraso
        $expiredReminders = 0;
        try {
            $conn->query("UPDATE recordatorios_programados SET enviado = 2, enviado_at = NOW() WHERE enviado = 0 AND fecha_programada < DATE_SUB(NOW(), INTERVAL 3 HOUR)");
            $expiredReminders = $conn->affected_rows;
        } catch (Throwable $e) {}

        $response["success"] = true;
        $response["modo"] = "global_sanitize";
        $response["conflictos_detectados"] = $globalConflicts;
        $response["conflictos_eliminados"] = $totalEliminados;
        $response["tokens_duplicados_limpiados"] = $dupesCleaned;
        $response["usuarios_col_limpiados"] = $usuariosColCleaned;
        $response["recordatorios_viejos_expirados"] = $expiredReminders;
        $response["mensaje"] = "Saneamiento global completado: Se resolvieron " . count($globalConflicts) . " tokens cruzados entre cuentas ($totalEliminados filas eliminadas) y se purgaron $expiredReminders recordatorios obsoletos.";

        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

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
