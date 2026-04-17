<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
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

$club_id = $_GET['club_id'] ?? null;

if (!$club_id) {
    http_response_code(400);
    echo json_encode(["error" => "ID del club es obligatorio"]);
    exit;
}

// Obtener usuarios vinculados a este club
// Usamos el nombre de columna 'usuario' en lugar de 'email' según el esquema real
$sql = "SELECT u.id, u.nombre, u.usuario as email, uc.rol 
        FROM usuarios u
        JOIN usuarios_clubes uc ON u.id = uc.usuario_id
        WHERE uc.club_id = ? AND uc.activo = 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $club_id);
$stmt->execute();
$result = $stmt->get_result();

$staff = [];
while ($row = $result->fetch_assoc()) {
    $staff[] = $row;
}

echo json_encode($staff);
?>
