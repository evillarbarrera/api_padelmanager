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

$club_id = $_GET['club_id'] ?? 0;

if (!$club_id) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "club_id es requerido"]);
    exit;
}

// Ensure table exists
$sqlTable = "CREATE TABLE IF NOT EXISTS club_whatsapp_programacion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    club_id INT NOT NULL,
    hora_envio VARCHAR(10) NOT NULL,
    dias_semana VARCHAR(50) DEFAULT '1,2,3,4,5,6,0',
    titulo_custom VARCHAR(255) DEFAULT '🚨 ¡CANCHAS DISPONIBLES!',
    promo_custom VARCHAR(255) DEFAULT '⚡ ¡Reserva antes de que se agoten!',
    telefono_contacto VARCHAR(50) DEFAULT '+56969083124',
    webhook_url VARCHAR(255) DEFAULT '',
    activo TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
$conn->query($sqlTable);

$stmt = $conn->prepare("SELECT * FROM club_whatsapp_programacion WHERE club_id = ? ORDER BY hora_envio ASC");
$stmt->bind_param("i", $club_id);
$stmt->execute();
$res = $stmt->get_result();

$programaciones = [];
while ($row = $res->fetch_assoc()) {
    $row['activo'] = (bool)$row['activo'];
    $programaciones[] = $row;
}

echo json_encode([
    "success" => true,
    "programaciones" => $programaciones
]);
?>
