<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
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

$admin_id = $_GET['admin_id'] ?? 0;

// Usamos COALESCE para priorizar la nueva columna en 'clubes', manteniendo compatibilidad con 'direcciones'
$sql_fields = "DISTINCT c.*, 
               COALESCE(NULLIF(c.region, ''), d.region) as region, 
               COALESCE(NULLIF(c.comuna, ''), d.comuna) as comuna";

if ($admin_id) {
    // Buscamos clubes donde sea el admin (dueño) O donde tenga un perfil activo en usuarios_clubes
    $sql = "SELECT $sql_fields 
            FROM clubes c 
            LEFT JOIN direcciones d ON d.club_id = c.id 
            LEFT JOIN usuarios_clubes uc ON uc.club_id = c.id 
            WHERE c.admin_id = ? OR (uc.usuario_id = ? AND uc.activo = 1)
            ORDER BY c.nombre ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $admin_id, $admin_id);
} else {
    $sql = "SELECT $sql_fields 
            FROM clubes c 
            LEFT JOIN direcciones d ON d.club_id = c.id 
            ORDER BY c.nombre ASC";
    $stmt = $conn->prepare($sql);
}

$stmt->execute();
$result = $stmt->get_result();

$clubes = [];
while ($row = $result->fetch_assoc()) {
    $clubes[] = $row;
}

echo json_encode($clubes);
?>
