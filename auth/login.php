<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ob_start(); // Start buffer

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit;
}

require_once "../db.php";

try {
    $rawInput = file_get_contents("php://input");
    $data = json_decode($rawInput, true);

    $usuario = trim($data['usuario'] ?? '');
    $password = trim($data['password'] ?? '');

    if ($usuario === '' || $password === '') {
        ob_clean();
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "error" => "Credenciales incorrectas",
            "error_details" => "Usuario y contraseña son requeridos"
        ]);
        exit;
    }

    // Asegurar columna club_guide_seen sin romper si no hay permisos
    try {
        $chkGuide = @$conn->query("SHOW COLUMNS FROM usuarios LIKE 'club_guide_seen'");
        if ($chkGuide && $chkGuide->num_rows == 0) {
            @$conn->query("ALTER TABLE usuarios ADD club_guide_seen TINYINT(1) DEFAULT 0");
        }
    } catch (Throwable $e) {
        // Ignorar si falla la verificación de columna
    }

    // Búsqueda insensible a mayúsculas/minúsculas para el email o username
    $sql = "SELECT id, usuario, password, rol, nombre, club_guide_seen FROM usuarios WHERE LOWER(usuario) = LOWER(?) LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Error al preparar la consulta de usuario: " . $conn->error);
    }
    $stmt->bind_param("s", $usuario);
    $stmt->execute();
    $result = $stmt->get_result();

    $debug_error = "Credenciales incorrectas";

    if ($user = $result->fetch_assoc()) {
        $passwordStored = (string)($user['password'] ?? '');
        $loginSuccess = false;
        $needsRehash = false;

        if ($passwordStored !== '') {
            // 1. Intentar verificar hash
            if (password_verify($password, $passwordStored)) {
                $loginSuccess = true;
                // Verificar si necesita rehash (ej. si cambió algoritmo)
                if (password_needs_rehash($passwordStored, PASSWORD_DEFAULT)) {
                    $needsRehash = true;
                }
            } 
            // 2. Fallback: verificar texto plano (Legacy migration)
            elseif ($password === $passwordStored) {
                $loginSuccess = true;
                $needsRehash = true; // Convertir a hash
            } else {
                $debug_error = "La contraseña no coincide con el registro";
            }
        } else {
            $debug_error = "El usuario no posee una contraseña configurada (posible inicio social)";
        }

        if ($loginSuccess) {
            // Auto-migrate password si es necesario
            if ($needsRehash) {
                try {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $updateSql = "UPDATE usuarios SET password = ? WHERE id = ?";
                    $stmtUpdate = $conn->prepare($updateSql);
                    if ($stmtUpdate) {
                        $stmtUpdate->bind_param("si", $newHash, $user['id']);
                        $stmtUpdate->execute();
                        $stmtUpdate->close();
                    }
                } catch (Throwable $e) {
                    error_log("Error al actualizar hash de password: " . $e->getMessage());
                }
            }

            // GENERACIÓN DE TOKEN DE SESIÓN
            $token = base64_encode($user['id'] . "|padel_academy|" . bin2hex(random_bytes(16)));
            
            require_once __DIR__ . '/auth_helper.php';
            registerUserSessionToken($conn, $user['id'], $token);

            // 3. Buscar Perfiles Activos (Club Admin, Staff, Entrenador, Jugador)
            $perfiles = getUserProfiles($conn, $user);

            ob_clean(); // Clean any buffer to avoid unwanted output
            echo json_encode([
                "success" => true,
                "token" => $token,
                "current_session_token" => $token,
                "rol" => $user['rol'],
                "id" => $user['id'],
                "nombre" => $user['nombre'],
                "club_guide_seen" => (int)($user['club_guide_seen'] ?? 0),
                "perfiles" => $perfiles
            ]);
            exit;
        }
    } else {
        $debug_error = "El usuario '$usuario' no existe en la base de datos";
    }

    ob_clean();
    http_response_code(401);
    echo json_encode([
        "success" => false, 
        "error" => "Credenciales incorrectas",
        "error_details" => $debug_error
    ]);
} catch (Throwable $e) {
    ob_clean();
    error_log("Fatal Login Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode([
        "success" => false, 
        "error" => "Error interno en el servidor", 
        "details" => $e->getMessage()
    ]);
}
