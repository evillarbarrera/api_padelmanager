<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$usuario_id = $data['usuario_id'] ?? null;
$club_id = $data['club_id'] ?? null;
$nuevo_rol = $data['rol'] ?? 'jugador'; // entrenador, administrador_club

if (!$usuario_id || !$club_id) {
    http_response_code(400);
    echo json_encode(["error" => "Faltan datos (usuario_id, club_id)"]);
    exit;
}

// Verificar si ya existe perfil para evitar duplicados, si existe actualizamos rol
$sqlCheck = "SELECT id FROM usuarios_clubes WHERE usuario_id = ? AND club_id = ?";
$stmt = $conn->prepare($sqlCheck);
$stmt->bind_param("ii", $usuario_id, $club_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows > 0) {
    // Actualizar rol existente
    $sqlUpd = "UPDATE usuarios_clubes SET rol = ? WHERE usuario_id = ? AND club_id = ?";
    $stmtU = $conn->prepare($sqlUpd);
    $stmtU->bind_param("sii", $nuevo_rol, $usuario_id, $club_id);
    $stmtU->execute();
    echo json_encode(["success" => true, "mensaje" => "Perfil actualizado a $nuevo_rol en este club."]);
} else {
    // Crear nuevo perfil
    $sqlIns = "INSERT INTO usuarios_clubes (usuario_id, club_id, rol) VALUES (?, ?, ?)";
    $stmtI = $conn->prepare($sqlIns);
    $stmtI->bind_param("iis", $usuario_id, $club_id, $nuevo_rol);
    $stmtI->execute();
    echo json_encode(["success" => true, "mensaje" => "Nuevo perfil de $nuevo_rol creado exitosamente."]);
}
?>
