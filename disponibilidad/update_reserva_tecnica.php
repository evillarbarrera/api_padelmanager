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
    $stmt->bind_param("iisis", $malla_id, $clase_id, $clase_titulo, $pack_id, $reserva_id);
    
    if ($stmt->execute()) {
        // 2.5 Actualizar cancha_id si viene en el payload
        if (isset($data['cancha_id'])) {
            $new_cancha_id = intval($data['cancha_id']);
            if ($new_cancha_id > 0) {
                $conn->query("UPDATE reservas SET cancha_id = $new_cancha_id WHERE id = $reserva_id");
                
                // Sincronizar reservas_cancha
                $rInfo = $conn->query("SELECT fecha, hora_inicio, hora_fin, club_id FROM reservas WHERE id = $reserva_id")->fetch_assoc();
                if ($rInfo) {
                    $cDate = $rInfo['fecha'];
                    $hIni = $rInfo['hora_inicio'];
                    $hFin = $rInfo['hora_fin'];
                    
                    $chkRC = $conn->query("SELECT id FROM reservas_cancha WHERE reserva_entrenador_id = $reserva_id LIMIT 1");
                    if ($chkRC && $rcRow = $chkRC->fetch_assoc()) {
                        $rcId = $rcRow['id'];
                        $conn->query("UPDATE reservas_cancha SET cancha_id = $new_cancha_id, fecha = '$cDate', hora_inicio = '$hIni', hora_fin = '$hFin', estado = 'Confirmada' WHERE id = $rcId");
                    } else {
                        $insRC = $conn->prepare("INSERT INTO reservas_cancha 
                            (cancha_id, usuario_id, nombre_externo, fecha, hora_inicio, hora_fin, precio, precio_total, pagado, estado, tipo_reserva, reserva_entrenador_id, entrenador_id)
                            VALUES (?, ?, '🎾 Clase de Entrenamiento', ?, ?, ?, 0, 0, 1, 'Confirmada', 'entrenamiento', ?, ?)");
                        $insRC->bind_param("iisssii", $new_cancha_id, $entrenador_id, $cDate, $hIni, $hFin, $reserva_id, $entrenador_id);
                        $insRC->execute();
                    }
                }
            } else {
                $conn->query("UPDATE reservas SET cancha_id = NULL WHERE id = $reserva_id");
                $conn->query("UPDATE reservas_cancha SET estado = 'Cancelada' WHERE reserva_entrenador_id = $reserva_id");
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
