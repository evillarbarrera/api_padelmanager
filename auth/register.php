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

// Auto-fix for column length if 'administrador_club' is being truncated
$conn->query("ALTER TABLE usuarios MODIFY COLUMN rol VARCHAR(50)");



$data = json_decode(file_get_contents("php://input"), true);

$nombre = $data['nombre'] ?? '';
$email = $data['email'] ?? ''; // usuario
$password = $data['password'] ?? '';
$rol = $data['rol'] ?? '';

// Debug Log
error_log("Registro iniciado - Usuario: $email, Rol recibido: '$rol'");

if (empty($nombre) || empty($email) || empty($password) || empty($rol)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Faltan datos obligatorios (nombre, email, password o rol)"]);
    exit;
}

// 1. Verificar si el usuario ya existe
$sqlCheck = "SELECT id FROM usuarios WHERE usuario = ?";
$stmtCheck = $conn->prepare($sqlCheck);
$stmtCheck->bind_param("s", $email);
$stmtCheck->execute();
$resultCheck = $stmtCheck->get_result();

if ($resultCheck->num_rows > 0) {
    http_response_code(409); 
    echo json_encode(["success" => false, "error" => "El correo electrónico ya está registrado"]);
    exit;
}

// 2. Hash password
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

// 3. Insertar
$sqlInsert = "INSERT INTO usuarios (usuario, password, rol, nombre) VALUES (?, ?, ?, ?)";
$stmtInsert = $conn->prepare($sqlInsert);

if (!$stmtInsert) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Error interno al preparar la consulta: " . $conn->error]);
    exit;
}

$stmtInsert->bind_param("ssss", $email, $passwordHash, $rol, $nombre);

if ($stmtInsert->execute()) {
    $newUserId = $conn->insert_id;
    $token = base64_encode($newUserId . "|padel_academy");
    
    // 4. Crear perfil en usuarios_clubes si viene club_id
    $club_id = $data['club_id'] ?? null;
    
    if ($club_id) {
        $sqlProfile = "INSERT INTO usuarios_clubes (usuario_id, club_id, rol) VALUES (?, ?, ?)";
        $stmtProfile = $conn->prepare($sqlProfile);
        $stmtProfile->bind_param("iis", $newUserId, $club_id, $rol);
        $stmtProfile->execute();
        
        // También actualizamos la columna legacy 'club_id' en usuarios por ahora
        $conn->query("UPDATE usuarios SET club_id = $club_id WHERE id = $newUserId");
    }

    // 5. Construir respuesta con perfiles
    $perfiles = [];
    if ($club_id) {
        // Fetch club name for completeness if possible, or just mock it for now
        $perfiles[] = [
            "id" => null, // We don't have the pivot ID easily unless we query back, but for UI it's okay
            "club_id" => $club_id,
            "rol" => $rol,
            "nivel" => null,
            "club_nombre" => "Club Seleccionado" // Ideally fetch this, but minor detail
        ];
    } else {
        $perfiles[] = [
            "id" => 0,
            "club_id" => null,
            "rol" => $rol,
            "nivel" => null,
            "club_nombre" => "Perfil Global"
        ];
    }

    echo json_encode([
        "success" => true,
        "message" => "Usuario registrado con éxito",
        "token" => $token,
        "usuario" => ["id" => $newUserId, "nombre" => $nombre, "rol" => $rol],
        "perfiles" => $perfiles
    ]);
} else {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Error al ejecutar el registro: " . $stmtInsert->error]);
}
