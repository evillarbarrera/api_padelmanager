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
$duracion_min = isset($data['duracion']) ? intval($data['duracion']) : 0;
$hora_fin_param = isset($data['hora_fin']) ? trim($data['hora_fin']) : '';

try {
    // 1. REPAIR: If pack_id is 0, we retrieve the current one from DB to prevent FK error
    // We also need jugador/entrenador for Auto-Activation
    $sqlFetch = "SELECT r.pack_id, r.entrenador_id, r.hora_inicio, r.hora_fin, rj.jugador_id 
                 FROM reservas r 
                 LEFT JOIN reserva_jugadores rj ON r.id = rj.reserva_id 
                 WHERE r.id = ? LIMIT 1";
    $stmtCurr = $conn->prepare($sqlFetch);
    $stmtCurr->bind_param("i", $reserva_id);
    $stmtCurr->execute();
    $currRes = $stmtCurr->get_result()->fetch_assoc();

    $jugador_id = $currRes['jugador_id'] ?? 0;
    $entrenador_id = $currRes['entrenador_id'] ?? 0;
    $curr_hora_inicio = $currRes['hora_inicio'] ?? '08:00:00';
    $curr_hora_fin = $currRes['hora_fin'] ?? null;

    if ($pack_id <= 0) {
        $pack_id = $currRes['pack_id'] ?? 0;
    }

    $new_hora_fin = $curr_hora_fin;
    if (!empty($hora_fin_param)) {
        $new_hora_fin = (strlen($hora_fin_param) === 5) ? ($hora_fin_param . ':00') : $hora_fin_param;
    } elseif ($duracion_min > 0 && !empty($curr_hora_inicio)) {
        $startSec = strtotime($curr_hora_inicio);
        if ($startSec !== false) {
            $new_hora_fin = date('H:i:s', $startSec + ($duracion_min * 60));
        }
    }

    // 2. Update Reservation
    if (!empty($new_hora_fin)) {
        $stmt = $conn->prepare("UPDATE reservas SET malla_id = ?, clase_id = ?, clase_titulo = ?, pack_id = ?, hora_fin = ? WHERE id = ?");
        $stmt->bind_param("iisisi", $malla_id, $clase_id, $clase_titulo, $pack_id, $new_hora_fin, $reserva_id);
    } else {
        $stmt = $conn->prepare("UPDATE reservas SET malla_id = ?, clase_id = ?, clase_titulo = ?, pack_id = ? WHERE id = ?");
        $stmt->bind_param("iisii", $malla_id, $clase_id, $clase_titulo, $pack_id, $reserva_id);
    }
    
    if ($stmt->execute()) {
        if (!empty($new_hora_fin)) {
            $stmtUpdRC = $conn->prepare("UPDATE reservas_cancha SET hora_fin = ? WHERE reserva_entrenador_id = ?");
            if ($stmtUpdRC) {
                $stmtUpdRC->bind_param("si", $new_hora_fin, $reserva_id);
                $stmtUpdRC->execute();
            }
        }
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
