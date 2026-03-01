<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Authorization
$headers = getallheaders();
$auth = $headers['Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

if ($auth !== 'Bearer ' . base64_encode("1|padel_academy")) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$entrenador_id = $data['entrenador_id'] ?? null;
$club_id = $data['club_id'] ?? null;

if (!$entrenador_id || !$club_id) {
    http_response_code(400);
    echo json_encode(["error" => "entrenador_id y club_id son obligatorios"]);
    exit;
}

// 1. Disponibilidad actual
$sqlDisp = "UPDATE disponibilidad_profesor SET club_id = ? WHERE profesor_id = ? AND (club_id IS NULL OR club_id = 0)";
$stmtDisp = $conn->prepare($sqlDisp);
$stmtDisp->bind_param("ii", $club_id, $entrenador_id);
$stmtDisp->execute();
$affectedDisp = $stmtDisp->affected_rows;

// 2. Packs (Grupales e Individuales)
$sqlPacks = "UPDATE packs SET club_id = ? WHERE entrenador_id = ? AND (club_id IS NULL OR club_id = 0)";
$stmtPacks = $conn->prepare($sqlPacks);
$stmtPacks->bind_param("ii", $club_id, $entrenador_id);
$stmtPacks->execute();
$affectedPacks = $stmtPacks->affected_rows;

// 3. Configuración Semanal (Semana Base)
$sqlConfig = "UPDATE entrenador_horarios_config SET club_id = ? WHERE entrenador_id = ? AND (club_id IS NULL OR club_id = 0)";
$stmtConfig = $conn->prepare($sqlConfig);
$stmtConfig->bind_param("ii", $club_id, $entrenador_id);
$stmtConfig->execute();
$affectedConfig = $stmtConfig->affected_rows;

// 4. Reservas (Opcional pero recomendado para historial)
$sqlRes = "UPDATE reservas SET club_id = ? WHERE entrenador_id = ? AND (club_id IS NULL OR club_id = 0)";
$stmtRes = $conn->prepare($sqlRes);
$stmtRes->bind_param("ii", $club_id, $entrenador_id);
$stmtRes->execute();

$totalAffected = $affectedDisp + $affectedPacks + $affectedConfig;

echo json_encode([
    "success" => true, 
    "affected" => $totalAffected, 
    "message" => "Sincronizados $affectedDisp horarios, $affectedPacks packs y $affectedConfig configuraciones al club."
]);

$stmtDisp->close();
$stmtPacks->close();
$stmtConfig->close();
$stmtRes->close();
$conn->close();
?>
