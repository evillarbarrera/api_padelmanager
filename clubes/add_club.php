<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$headers = getallheaders();
require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    http_response_code(400);
    echo json_encode(["error" => "Datos inválidos"]);
    exit;
}

$nombre = $data['nombre'] ?? '';
$direccion = $data['direccion'] ?? '';
$region = $data['region'] ?? '';
$comuna = $data['comuna'] ?? '';
$telefono = $data['telefono'] ?? '';
$instagram = $data['instagram'] ?? '';
$email = $data['email'] ?? '';
$admin_id = $data['admin_id'] ?? null;
$rol = $data['rol'] ?? 'administrador_club'; // Default to admin for backwards compatibility
$reservas_activas = isset($data['reservas_activas']) ? (int)$data['reservas_activas'] : 1;
$academia_activa = isset($data['academia_activa']) ? (int)$data['academia_activa'] : 1;

if (empty($nombre) || empty($admin_id)) {
    http_response_code(400);
    echo json_encode(["error" => "Nombre y admin_id son obligatorios"]);
    exit;
}

$sql = "INSERT INTO clubes (nombre, direccion, region, comuna, telefono, instagram, email, admin_id, reservas_activas, academia_activa) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sssssssiii", $nombre, $direccion, $region, $comuna, $telefono, $instagram, $email, $admin_id, $reservas_activas, $academia_activa);

try {
    if ($stmt->execute()) {
        $club_id = $conn->insert_id;
        
        // Tabla direcciones por compatibilidad
        $sqlDir = "INSERT INTO direcciones (club_id, usuario_id, region, comuna, calle) VALUES (?, NULL, ?, ?, ?)";
        $stmtDir = $conn->prepare($sqlDir);
        $stmtDir->bind_param("isss", $club_id, $region, $comuna, $direccion);
        $stmtDir->execute();

        // Rol asignado (por defecto administrador_club, pero puede ser entrenador si se especifica)
        $sqlPerfil = "INSERT INTO usuarios_clubes (usuario_id, club_id, rol) VALUES (?, ?, ?)";
        $stmtPerfil = $conn->prepare($sqlPerfil);
        $stmtPerfil->bind_param("iis", $admin_id, $club_id, $rol);
        $stmtPerfil->execute();

        echo json_encode(["success" => true, "id" => $club_id]);
    } else {
        throw new Exception($stmt->error);
    }
} catch (mysqli_sql_exception $e) {
    if ($e->getCode() === 1062) {
        http_response_code(409);
        echo json_encode(["error" => "El nombre del club ya está registrado."]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Error al ejecutar: " . $e->getMessage()]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Error al crear club: " . $e->getMessage()]);
}
?>
