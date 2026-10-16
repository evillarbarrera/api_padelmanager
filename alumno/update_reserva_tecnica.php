<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);

if (!$data || !isset($data['reserva_id'])) {
    http_response_code(400);
    echo json_encode(["error" => "reserva_id es obligatorio"]);
    exit;
}

$reserva_id = intval($data['reserva_id']);
$malla_id = intval($data['malla_id'] ?? 0);
$clase_id = intval($data['clase_id'] ?? 0);
$clase_titulo = $data['clase_titulo'] ?? '';
$pack_id = intval($data['pack_id'] ?? 0);

try {
    // 1. REPAIR: If pack_id is 0, we retrieve the current one from DB to prevent FK error
    // We also need jugador/entrenador for Auto-Activation
    $sqlFetch = "SELECT r.pack_id, r.entrenador_id, rj.jugador_id 
                 FROM reservas r 
                 JOIN reserva_jugadores rj ON r.id = rj.reserva_id 
                 WHERE r.id = ? LIMIT 1";
    $stmtCurr = $conn->prepare($sqlFetch);
    $stmtCurr->bind_param("i", $reserva_id);
    $stmtCurr->execute();
    $currRes = $stmtCurr->get_result()->fetch_assoc();

    $jugador_id = $currRes['jugador_id'] ?? 0;
    $entrenador_id = $currRes['entrenador_id'] ?? 0;
    if ($pack_id <= 0) {
        $pack_id = $currRes['pack_id'] ?? 0;
    }

    // 2. Update Reservation
    $stmt = $conn->prepare("UPDATE reservas SET malla_id = ?, clase_id = ?, clase_titulo = ?, pack_id = ? WHERE id = ?");
    $stmt->bind_param("iiiis", $malla_id, $clase_id, $clase_titulo, $pack_id, $reserva_id);
    
    if ($stmt->execute()) {
        // 3. AUTO-ACTIVATE RoadMap if mesh provided
        if ($malla_id > 0 && $pack_id > 0 && $jugador_id > 0) {
            $checkM = $conn->prepare("SELECT id FROM alumno_malla_seguimiento WHERE jugador_id = ? AND entrenador_id = ? AND pack_id = ? AND estado = 'activo' LIMIT 1");
            $checkM->bind_param("iii", $jugador_id, $entrenador_id, $pack_id);
            $checkM->execute();
            if ($checkM->get_result()->num_rows === 0) {
                $insM = $conn->prepare("INSERT INTO alumno_malla_seguimiento (jugador_id, entrenador_id, pack_id, malla_id, estado, fecha_inicio) VALUES (?, ?, ?, ?, 'activo', NOW())");
                $insM->bind_param("iiii", $jugador_id, $entrenador_id, $pack_id, $malla_id);
                $insM->execute();
            }
        }
        echo json_encode(["ok" => true, "message" => "Planificación técnica actualizada y sincronizada"]);
    } else {
        throw new Exception($stmt->error);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Error al actualizar reserva: " . $e->getMessage()]);
}
