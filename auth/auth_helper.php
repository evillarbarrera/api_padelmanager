<?php
/**
 * Auth Helper for Training Padel Academy
 * Validates token format and allows unlimited concurrent device sessions.
 */

/**
 * Register login timestamp and optional token session without device restrictions.
 */
function registerUserSessionToken($conn, $userId, $newToken) {
    if (!$conn || $userId <= 0 || empty($newToken)) return;
    
    $now = date('Y-m-d H:i:s');
    $upStmt = $conn->prepare("UPDATE usuarios SET last_login_at = ? WHERE id = ?");
    if ($upStmt) {
        $upStmt->bind_param("si", $now, $userId);
        $upStmt->execute();
    }
}

/**
 * Revoke specific session token (used during explicit logout).
 */
function revokeUserSessionToken($conn, $userId, $rawToken) {
    // Unlimited multi-device mode: Logout clears client token locally.
    if (!$conn || $userId <= 0) return;
}

function validateToken() {
    $auth = '';

    // 1. Get headers via getallheaders (Apache) if available
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    
    // Check headers case-insensitively
    foreach ($headers as $key => $value) {
        $keyLower = strtolower($key);
        if ($keyLower === 'authorization' || $keyLower === 'x-authorization') {
            $auth = $value;
            break;
        }
    }

    // Fallbacks to standard Server variables
    if (empty($auth)) {
        $possible_keys = [
            'HTTP_AUTHORIZATION', 
            'REDIRECT_HTTP_AUTHORIZATION', 
            'HTTP_X_AUTHORIZATION', 
            'REDIRECT_HTTP_X_AUTHORIZATION'
        ];
        foreach ($possible_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $auth = $_SERVER[$key];
                break;
            }
        }
    }

    // 🏆 ULTIMATE FALLBACK: Query Parameter (for Safari/Mobile bypass)
    if (empty($auth) && !empty($_GET['token'])) {
        $auth = 'Bearer ' . $_GET['token'];
    }

    if (empty($auth)) {
        return false;
    }

    if (strpos($auth, 'Bearer ') !== 0) {
        return false;
    }

    $rawToken = trim(substr($auth, 7));
    if (empty($rawToken)) {
        return false;
    }

    $userId = 0;
    $tokenDecoded = base64_decode($rawToken, true);

    if ($tokenDecoded) {
        $parts = explode('|', $tokenDecoded);
        if (count($parts) >= 2) {
            $possibleId = intval($parts[0]);
            $secret = $parts[1];
            if ($secret === 'padel_academy' && $possibleId > 0) {
                $userId = $possibleId;
            }
        }
    }

    // Verify user exists in DB
    global $conn;
    if (!$conn) {
        @include_once __DIR__ . '/../db.php';
    }

    if ($userId > 0 && isset($conn) && $conn) {
        $stmt = $conn->prepare("SELECT id FROM usuarios WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                return $userId;
            }
        }
    }

    return $userId > 0 ? $userId : false;
}

function validateActiveSession($connParam = null) {
    global $conn;
    $db = $connParam ?? $conn;
    if (!$db) {
        @include_once __DIR__ . '/../db.php';
        $db = $conn ?? null;
    }

    $userId = validateToken();
    if (!$userId) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'code' => 'UNAUTHORIZED',
            'status' => 'unauthorized',
            'message' => 'Token inválido o sesión finalizada.'
        ]);
        exit;
    }

    if ($db) {
        $stmt = $db->prepare("SELECT id, nombre, usuario, rol, club_id FROM usuarios WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($user = $res->fetch_assoc()) {
                return $user;
            }
        }
    }

    return ['id' => $userId];
}

function sendUnauthorized($details = "Token mismatch or missing") {
    http_response_code(401);
    echo json_encode([
        "error" => "Unauthorized",
        "details" => $details
    ]);
    exit;
}

/**
 * Obtiene todos los perfiles de acceso (Club Admin, Staff, Entrenador, Jugador) para un usuario.
 */
function getUserProfiles($conn, $user) {
    if (!$conn || !isset($user['id'])) return [];
    
    $userId = (int)$user['id'];
    $perfiles = [];
    
    // 1. Perfiles registrados en la tabla usuarios_clubes
    $sqlP = "SELECT uc.id, uc.club_id, uc.rol, uc.nivel, c.nombre as club_nombre, c.admin_id 
             FROM usuarios_clubes uc
             JOIN clubes c ON uc.club_id = c.id
             WHERE uc.usuario_id = ? AND uc.activo = 1";
    $stmtP = $conn->prepare($sqlP);
    if ($stmtP) {
        $stmtP->bind_param("i", $userId);
        $stmtP->execute();
        $resP = $stmtP->get_result();
        if ($resP) {
            while ($p = $resP->fetch_assoc()) {
                $dbRol = $p['rol'] ?? 'jugador';
                if ($dbRol === 'entrenador' || $dbRol === 'jugador') {
                    // Mantener rol
                } else {
                    if ($p['admin_id'] != $userId) {
                        $p['rol'] = 'staff_club';
                    } else {
                        $p['rol'] = 'administrador_club';
                    }
                }
                $perfiles[] = $p;
            }
        }
    }

    $baseRole = $user['rol'] ?? 'jugador';

    // 2. Comprobar si el usuario es dueño directo de algún club (clubes.admin_id)
    $sqlOwn = "SELECT id as club_id, nombre as club_nombre FROM clubes WHERE admin_id = ? AND activo = 1";
    $stmtOwn = $conn->prepare($sqlOwn);
    if ($stmtOwn) {
        $stmtOwn->bind_param("i", $userId);
        $stmtOwn->execute();
        $resOwn = $stmtOwn->get_result();
        if ($resOwn) {
            while ($c = $resOwn->fetch_assoc()) {
                $alreadyAdded = false;
                foreach ($perfiles as $existing) {
                    if ($existing['club_id'] == $c['club_id'] && $existing['rol'] === 'administrador_club') {
                        $alreadyAdded = true;
                        break;
                    }
                }
                if (!$alreadyAdded) {
                    $perfiles[] = [
                        "id" => 0,
                        "club_id" => (int)$c['club_id'],
                        "rol" => "administrador_club",
                        "nivel" => null,
                        "club_nombre" => $c['club_nombre'],
                        "admin_id" => $userId
                    ];
                }
            }
        }
    }

    // 3. Si el rol base en usuarios es administrador o staff de club y no tiene perfil en perfiles
    if (in_array($baseRole, ['administrador_club', 'staff_club', 'administrador'])) {
        $hasAdmin = false;
        foreach ($perfiles as $p) {
            if (in_array($p['rol'], ['administrador_club', 'staff_club', 'administrador'])) {
                $hasAdmin = true;
                break;
            }
        }
        if (!$hasAdmin) {
            $perfiles[] = [
                "id" => 0,
                "club_id" => null,
                "rol" => $baseRole,
                "nivel" => null,
                "club_nombre" => "Administrador de Club",
                "admin_id" => $userId
            ];
        }
    }

    // 4. Si el rol base en usuarios es entrenador
    if ($baseRole === 'entrenador') {
        $hasCoach = false;
        foreach ($perfiles as $p) {
            if ($p['rol'] === 'entrenador') {
                $hasCoach = true;
                break;
            }
        }
        if (!$hasCoach) {
            $perfiles[] = [
                "id" => 0,
                "club_id" => null,
                "rol" => "entrenador",
                "nivel" => null,
                "club_nombre" => "Perfil Entrenador",
                "admin_id" => null
            ];
        }
    }

    // 5. SIEMPRE asegurar que exista un perfil de jugador (todo admin o entrenador también puede jugar/reservar)
    $hasJugador = false;
    foreach ($perfiles as $p) {
        if ($p['rol'] === 'jugador') {
            $hasJugador = true;
            break;
        }
    }
    if (!$hasJugador) {
        $perfiles[] = [
            "id" => 0,
            "club_id" => null,
            "rol" => "jugador",
            "nivel" => $user['nivel'] ?? null,
            "club_nombre" => "Perfil Jugador",
            "admin_id" => null
        ];
    }

    return $perfiles;
}
?>
