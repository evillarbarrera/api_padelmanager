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

$club_id = $_GET['club_id'] ?? 0;

if (!$club_id) {
    http_response_code(400);
    echo json_encode(["error" => "club_id es requerido"]);
    exit;
}

// SILENT SCHEMA FIX
function ensureCanchaColumns($conn) {
    $cols = [
        'categoria' => "VARCHAR(50) DEFAULT 'cancha_padel'",
        'icono' => "VARCHAR(50) DEFAULT '🎾'",
        'descripcion' => "TEXT NULL",
        'capacidad' => "INT DEFAULT 4",
        'precio_60_alto' => "DECIMAL(10,2) DEFAULT 0.00",
        'precio_90_alto' => "DECIMAL(10,2) DEFAULT 0.00",
        'precio_120_alto' => "DECIMAL(10,2) DEFAULT 0.00",
        'hora_inicio_bajo' => "TIME DEFAULT '07:00:00'",
        'hora_fin_bajo' => "TIME DEFAULT '18:00:00'",
        'hora_inicio_alto' => "TIME DEFAULT '18:00:00'",
        'hora_fin_alto' => "TIME DEFAULT '24:00:00'"
    ];
    foreach ($cols as $col => $def) {
        $check = $conn->query("SHOW COLUMNS FROM canchas LIKE '$col'");
        if ($check && $check->num_rows == 0) {
            $conn->query("ALTER TABLE canchas ADD `$col` $def");
        }
    }
}
ensureCanchaColumns($conn);

$sql = "SELECT * FROM canchas WHERE club_id = ? AND activa = 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $club_id);
$stmt->execute();
$result = $stmt->get_result();

$canchas = [];
while ($row = $result->fetch_assoc()) {
    $canchas[] = $row;
}

echo json_encode($canchas);
?>
