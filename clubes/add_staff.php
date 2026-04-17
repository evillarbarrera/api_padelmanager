<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);

$nombre = $data['nombre'] ?? '';
$email = $data['email'] ?? '';
$password = $data['password'] ?? '';
$club_id = $data['club_id'] ?? null;
$rol = $data['rol'] ?? 'staff_club';

if (empty($nombre) || empty($email) || empty($club_id)) {
    http_response_code(400);
    echo json_encode(["error" => "Datos incompletos"]);
    exit;
}

// 1. Verificar si el usuario ya existe utilizando la columna 'usuario'
$sqlUser = "SELECT id FROM usuarios WHERE LOWER(usuario) = LOWER(?)";
$stmtUser = $conn->prepare($sqlUser);
$stmtUser->bind_param("s", $email);
$stmtUser->execute();
$resUser = $stmtUser->get_result();
$usuario_id = null;

if ($resUser->num_rows > 0) {
    $row = $resUser->fetch_assoc();
    $usuario_id = $row['id'];
} else {
    // Crear nuevo usuario si no existe
    if (empty($password)) {
        http_response_code(400);
        echo json_encode(["error" => "Se requiere una contraseña para nuevos usuarios"]);
        exit;
    }
    
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    // En esta DB la columna del login es 'usuario', no 'email'
    $sqlNew = "INSERT INTO usuarios (nombre, usuario, password, rol) VALUES (?, ?, ?, 'staff_club')"; 
    $stmtNew = $conn->prepare($sqlNew);
    $stmtNew->bind_param("sss", $nombre, $email, $hashed_password);
    
    if ($stmtNew->execute()) {
        $usuario_id = $conn->insert_id;
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Error al crear usuario base: " . $conn->error]);
        exit;
    }
}

// 2. Vincular usuario al club en 'usuarios_clubes'
// Verificamos si ya tiene un perfil (activo o inactivo)
$sqlCheck = "SELECT id, activo FROM usuarios_clubes WHERE usuario_id = ? AND club_id = ?";
$stmtCheck = $conn->prepare($sqlCheck);
$stmtCheck->bind_param("ii", $usuario_id, $club_id);
$stmtCheck->execute();
$resCheck = $stmtCheck->get_result();

if ($resCheck->num_rows > 0) {
    $rowCheck = $resCheck->fetch_assoc();
    if ($rowCheck['activo'] == 1) {
        http_response_code(409);
        echo json_encode(["error" => "El usuario ya tiene acceso activo a este club"]);
        exit;
    } else {
        // Reactivamos el perfil si ya existía pero estaba desactivado
        $sqlReact = "UPDATE usuarios_clubes SET activo = 1, rol = ? WHERE id = ?";
        $stmtReact = $conn->prepare($sqlReact);
        $stmtReact->bind_param("si", $rol, $rowCheck['id']);
        if ($stmtReact->execute()) {
            echo json_encode(["success" => true, "usuario_id" => $usuario_id]);
            exit;
        }
    }
}

// Si no existía, lo creamos nuevo con activo = 1
$sqlLink = "INSERT INTO usuarios_clubes (usuario_id, club_id, rol, activo) VALUES (?, ?, ?, 1)";
$stmtLink = $conn->prepare($sqlLink);
$stmtLink->bind_param("iis", $usuario_id, $club_id, $rol);

if ($stmtLink->execute()) {
    echo json_encode(["success" => true, "usuario_id" => $usuario_id]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al vincular usuario con el club: " . $conn->error]);
}
?>
