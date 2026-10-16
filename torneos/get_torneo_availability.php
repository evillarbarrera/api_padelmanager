<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=UTF-8");

include_once '../db.php';

// Manejo de peticiones preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0;

if ($torneo_id <= 0) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid torneo_id"]);
    exit();
}

$sql = "SELECT fecha, hora FROM torneo_horarios_disponibilidad WHERE torneo_id = $torneo_id ORDER BY fecha, hora";
$result = $conn->query($sql);

$grid = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $fecha = $row['fecha'];
        $hora = (int)$row['hora'];
        
        if (!isset($grid[$fecha])) {
            $grid[$fecha] = [];
        }
        $grid[$fecha][] = $hora;
    }
}

echo json_encode(["status" => "success", "torneo_id" => $torneo_id, "grid" => $grid]);

$conn->close();
?>
