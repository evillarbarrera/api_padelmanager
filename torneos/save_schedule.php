<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$schedule = $data['programacion'] ?? [];

if (empty($schedule)) {
    echo json_encode(["error" => "No hay datos recibidos de programación"]);
    exit;
}

$conn->begin_transaction();

try {
    $stmt = $conn->prepare("UPDATE torneo_partidos_v2 SET fecha_hora_inicio = ?, cancha_id = ? WHERE id = ?");
    
    $updatedCount = 0;
    foreach ($schedule as $match) {
        $fecha = $match['fecha_inicio']; // Formato YYYY-MM-DD HH:MM:SS
        $cancha = $match['cancha_id'] ? $match['cancha_id'] : null;
        $id = $match['partido_id'];

        $stmt->bind_param("sii", $fecha, $cancha, $id);
        $stmt->execute();
        $updatedCount++;
    }

    $conn->commit();
    echo json_encode(["success" => true, "mensaje" => "$updatedCount partidos programados correctamente."]);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(["error" => "Error al guardar: " . $e->getMessage()]);
}
?>
