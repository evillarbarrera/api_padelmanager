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

$data = json_decode(file_get_contents("php://input"), true);

$usuario = trim($data['usuario'] ?? '');
$password = trim($data['password'] ?? '');

// Búsqueda insensible a mayúsculas/minúsculas para el email
$sql = "SELECT id, usuario, password, rol, nombre FROM usuarios WHERE LOWER(usuario) = LOWER(?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $usuario);
$stmt->execute();
$result = $stmt->get_result();

$debug_error = "Credenciales incorrectas";

try {
    if ($user = $result->fetch_assoc()) {
      $passwordStored = $user['password'];
      $loginSuccess = false;
      $needsRehash = false;

      // 1. Intentar verificar hash
      if (password_verify($password, $passwordStored)) {
          $loginSuccess = true;
          // Verificar si necesita rehash (ej. si cambiaste algoritmo)
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

      if ($loginSuccess) {
          
          // Auto-migrate password si es necesario
          if ($needsRehash) {
              $newHash = password_hash($password, PASSWORD_DEFAULT);
              $updateSql = "UPDATE usuarios SET password = ? WHERE id = ?";
              $stmtUpdate = $conn->prepare($updateSql);
              $stmtUpdate->bind_param("si", $newHash, $user['id']);
              $stmtUpdate->execute();
          }

          // 3. Buscar Perfiles Activos en Clubes
          $perfiles = [];
          $sqlP = "SELECT uc.id, uc.club_id, uc.rol, uc.nivel, c.nombre as club_nombre, c.admin_id 
                   FROM usuarios_clubes uc
                   JOIN clubes c ON uc.club_id = c.id
                   WHERE uc.usuario_id = ? AND uc.activo = 1";
          $stmtP = $conn->prepare($sqlP);
          if (!$stmtP) {
              error_log("Login Error (stmtP): " . $conn->error);
          } else {
              $stmtP->bind_param("i", $user['id']);
              $stmtP->execute();
              $resP = $stmtP->get_result();
              $perfiles = [];
              
              // 2.1 Club Profiles
              if ($resP) {
                  while ($p = $resP->fetch_assoc()) {
                      $dbRol = $p['rol'] ?? 'jugador';
                      
                      // Si el rol es 'entrenador' o 'jugador', lo respetamos (requerimiento de disponibilidad)
                      if ($dbRol === 'entrenador' || $dbRol === 'jugador') {
                        // Keep the role from the database
                      } else {
                        // SEGURIDAD para perfiles de gestión: 
                        // Si el usuario NO es el dueño (admin_id), forzar rol staff_club 
                        if ($p['admin_id'] != $user['id']) {
                            $p['rol'] = 'staff_club';
                        } else {
                            $p['rol'] = 'administrador_club';
                        }
                      }
                      $perfiles[] = $p;
                  }
              }
          }
          
          // 2.2 Base Identity Profile (perfil global del usuario)
          // Detectamos si es redundante añadir el perfil base si ya tiene uno específico de club
          $userRole = $user['rol'] ?? 'jugador';
          $isManagementRole = (in_array($userRole, ['staff_club', 'administrador_club']));
          $hasClubProfiles = (count($perfiles) > 0);
          
          // Solo añadimos el perfil base si:
          // 1. NO es un rol de gestión (jugador, entrenador, super-admin siempre tienen perfil base)
          // 2. O si es un rol de gestión pero NO tiene perfiles de club asignados todavía
          if (!$isManagementRole || !$hasClubProfiles) {
              $label = "Perfil " . ucfirst(str_replace('_', ' ', $userRole));
              if ($userRole === 'staff_club') $label = "Perfil Personal";
              if ($userRole === 'administrador_club') $label = "Perfil Administrador";
              if ($userRole === 'jugador') $label = "Perfil Jugador";

              $perfiles[] = [
                  "id" => 0,
                  "club_id" => null,
                  "rol" => $user['rol'] ?? 'jugador',
                  "nivel" => null,
                  "club_nombre" => $label
              ];
          }

        // TOKEN SIMPLE (para todas las llamadas)
        $token = base64_encode($user['id'] . "|padel_academy"); 

        ob_clean(); // Clean any buffer to avoid unwanted output
        echo json_encode([
          "success" => true,
          "token" => $token,
          "rol" => $user['rol'],
          "id" => $user['id'],
          "nombre" => $user['nombre'],
          "perfiles" => $perfiles
        ]);
        exit;
      }
    } else {
        $debug_error = "El usuario '$usuario' no existe en la base de datos";
    }

    http_response_code(401);
    echo json_encode([
        "success" => false, 
        "error" => "Credenciales incorrectas",
        "error_details" => $debug_error
    ]);
} catch (Exception $e) {
    error_log("Fatal Login Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Internal Server Error", "details" => $e->getMessage()]);
}
