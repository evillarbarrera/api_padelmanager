<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit;
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);

$usuario = $data['usuario'] ?? '';
$password = $data['password'] ?? '';

$sql = "SELECT id, usuario, password, rol, nombre FROM usuarios WHERE usuario = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $usuario);
$stmt->execute();
$result = $stmt->get_result();

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
      $sqlP = "SELECT uc.id, uc.club_id, uc.rol, uc.nivel, c.nombre as club_nombre 
               FROM usuarios_clubes uc
               JOIN clubes c ON uc.club_id = c.id
               WHERE uc.usuario_id = ? AND uc.activo = 1";
      $stmtP = $conn->prepare($sqlP);
      $stmtP->bind_param("i", $user['id']);
      $stmtP->execute();
      $resP = $stmtP->get_result();
      $perfiles = [];
      
      // 2.1 Club Profiles
      while ($p = $resP->fetch_assoc()) {
          $perfiles[] = $p;
      }
      
      // 2.2 Base Identity Profile (from usuarios table)
      // Always add the base role as a profile option (e.g. Entrenador, Jugador)
      $perfiles[] = [
          "id" => 0,
          "club_id" => null,
          "rol" => $user['rol'],
          "nivel" => null,
          "club_nombre" => "Perfil " . ucfirst($user['rol'])
      ];

    // TOKEN SIMPLE (para todas las llamadas)
    $token = base64_encode($user['id'] . "|padel_academy"); 

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
}

http_response_code(401);
echo json_encode(["success" => false, "error" => "Credenciales incorrectas"]);
