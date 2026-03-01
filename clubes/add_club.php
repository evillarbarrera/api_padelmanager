<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$headers = getallheaders();
$auth = $headers['Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

if ($auth !== 'Bearer ' . base64_encode("1|padel_academy")) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
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

if (empty($nombre) || empty($admin_id)) {
    http_response_code(400);
    echo json_encode(["error" => "Nombre y admin_id son obligatorios"]);
    exit;
}

$sql = "INSERT INTO clubes (nombre, direccion, telefono, instagram, email, admin_id) VALUES (?, ?, ?, ?, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sssssi", $nombre, $direccion, $telefono, $instagram, $email, $admin_id);

try {
    if ($stmt->execute()) {
        $club_id = $conn->insert_id;
        
        // Ahora guardamos la dirección detallada en la tabla 'direcciones' vinculada al club
        $sqlDir = "INSERT INTO direcciones (club_id, usuario_id, region, comuna, calle) VALUES (?, NULL, ?, ?, ?)";
        $stmtDir = $conn->prepare($sqlDir);
        $stmtDir->bind_param("isss", $club_id, $region, $comuna, $direccion);
        $stmtDir->execute();

        // NUEVO: Crear automáticamente el perfil de Administrador para este usuario en la tabla de perfiles
        $sqlPerfil = "INSERT INTO usuarios_clubes (usuario_id, club_id, rol) VALUES (?, ?, 'administrador_club')";
        $stmtPerfil = $conn->prepare($sqlPerfil);
        $stmtPerfil->bind_param("ii", $admin_id, $club_id);
        $stmtPerfil->execute();

        echo json_encode(["success" => true, "id" => $club_id]);
    } else {
        throw new Exception($stmt->error);
    }
} catch (mysqli_sql_exception $e) {
    if ($e->getCode() === 1062) {
        http_response_code(409); // Conflict
        echo json_encode(["error" => "El nombre del club ya está registrado. Por favor elige otro."]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Error al ejecutar: " . $e->getMessage()]);
    }
} catch (Exception $e) {
    // Si la versión de PHP/MySQLi driver es antigua y no tira exception
    if ($conn->errno === 1062) {
        http_response_code(409);
        echo json_encode(["error" => "El nombre del club ya está registrado. Por favor elige otro."]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Error al crear club: " . $conn->error]);
    }
}
?>
