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
$days_count = $data['days_ahead'] ?? 30; // Default 30 days

if (!$entrenador_id) {
    http_response_code(400);
    echo json_encode(["error" => "entrenador_id es obligatorio"]);
    exit;
}

// 1. Get Config
$sqlConfig = "SELECT * FROM entrenador_horarios_config WHERE entrenador_id = ?";
$stmtC = $conn->prepare($sqlConfig);
$stmtC->bind_param("i", $entrenador_id);
$stmtC->execute();
$resConfig = $stmtC->get_result();

$configByDay = [];
while ($row = $resConfig->fetch_assoc()) {
    $configByDay[$row['dia_semana']][] = $row;
}
$stmtC->close();

if (empty($configByDay)) {
    echo json_encode(["success" => false, "message" => "No hay configuración por defecto definida."]);
    exit;
}

$conn->begin_transaction();

try {
    $today = new DateTime();
    $stmtInsert = $conn->prepare("INSERT IGNORE INTO disponibilidad_profesor (profesor_id, fecha_inicio, fecha_fin, club_id, activo) VALUES (?, ?, ?, ?, 1)");

    for ($i = 0; $i < $days_count; $i++) {
        $currentDate = clone $today;
        $currentDate->modify("+$i day");
        $dayOfWeek = $currentDate->format('w'); // 0 (Dom) - 6 (Sab)
        $dateStr = $currentDate->format('Y-m-d');

        if (isset($configByDay[$dayOfWeek])) {
            foreach ($configByDay[$dayOfWeek] as $conf) {
                // Generate blocks based on duration
                $start = new DateTime($dateStr . ' ' . $conf['hora_inicio']);
                $end = new DateTime($dateStr . ' ' . $conf['hora_fin']);
                $duration = $conf['duracion_bloque'];
                $club_id = $conf['club_id'] ?? 1;

                while ($start < $end) {
                    $blockEnd = clone $start;
                    $blockEnd->modify("+$duration minutes");
                    if ($blockEnd > $end) break;

                    $sStr = $start->format('Y-m-d H:i:s');
                    $eStr = $blockEnd->format('Y-m-d H:i:s');

                    // Check existence before insert
                    $sqlCheck = "SELECT id FROM disponibilidad_profesor 
                                 WHERE profesor_id = ? AND fecha_inicio = ? AND fecha_fin = ?";
                    $stmtCheck = $conn->prepare($sqlCheck);
                    $stmtCheck->bind_param("iss", $entrenador_id, $sStr, $eStr);
                    $stmtCheck->execute();
                    $exists = $stmtCheck->get_result()->fetch_assoc();
                    $stmtCheck->close();

                    if (!$exists) {
                        $stmtInsert->bind_param("issi", $entrenador_id, $sStr, $eStr, $club_id);
                        $stmtInsert->execute();
                    }

                    $start = $blockEnd;
                }
            }
        }
    }

    $stmtInsert->close();
    $conn->commit();
    echo json_encode(["success" => true, "applied_days" => $days_count]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}

$conn->close();
