<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$entrenador_id = $_GET['entrenador_id'] ?? null;

if (!$entrenador_id) {
    http_response_code(400);
    echo json_encode(["error" => "entrenador_id es obligatorio"]);
    exit;
}

$sql = "SELECT * FROM entrenador_horarios_config WHERE entrenador_id = ? ORDER BY dia_semana";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $entrenador_id);
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = [
        "dia_semana" => (int)$row['dia_semana'],
        "hora_inicio" => substr($row['hora_inicio'], 0, 5),
        "hora_fin" => substr($row['hora_fin'], 0, 5),
        "duracion_bloque" => (int)$row['duracion_bloque']
    ];
}

echo json_encode($data);
$stmt->close();
$conn->close();
