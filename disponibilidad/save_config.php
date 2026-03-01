<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$entrenador_id = $data['entrenador_id'] ?? null;
$config = $data['config'] ?? [];

if (!$entrenador_id) {
    http_response_code(400);
    echo json_encode(["error" => "entrenador_id es obligatorio"]);
    exit;
}

$conn->begin_transaction();

try {
    // Delete existing config
    $stmtDelete = $conn->prepare("DELETE FROM entrenador_horarios_config WHERE entrenador_id = ?");
    $stmtDelete->bind_param("i", $entrenador_id);
    $stmtDelete->execute();
    $stmtDelete->close();

    // Insert new config
    if (!empty($config)) {
        $stmtInsert = $conn->prepare("INSERT INTO entrenador_horarios_config (entrenador_id, club_id, dia_semana, hora_inicio, hora_fin, duracion_bloque) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($config as $item) {
            $c_id = $item['club_id'] ?? null;
            $stmtInsert->bind_param("iiissi", 
                $entrenador_id, 
                $c_id,
                $item['dia_semana'], 
                $item['hora_inicio'], 
                $item['hora_fin'],
                $item['duracion_bloque']
            );
            $stmtInsert->execute();
        }
        $stmtInsert->close();
    }

    $conn->commit();
    echo json_encode(["success" => true]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}

$conn->close();
