<?php
// Shared logic to fulfill a successful payment
require_once "../db.php";

function fulfillPayment($conn, $data) {
    $pack_id = (int)$data['pack_id'];
    $jugador_id = (int)$data['jugador_id'];
    $reserva_id = $data['reserva_id'] ?? null;

    // 1. Get Pack Details
    $sqlPack = "SELECT tipo, capacidad_maxima, cupos_ocupados FROM packs WHERE id = ?";
    $stmtPack = $conn->prepare($sqlPack);
    $stmtPack->bind_param("i", $pack_id);
    $stmtPack->execute();
    $resPack = $stmtPack->get_result()->fetch_assoc();

    if (!$resPack) return false;

    $tipo = $resPack['tipo'];

    if ($tipo === 'grupal') {
        $conn->begin_transaction();
        try {
            $fecha_inicio = date('Y-m-d');
            $fecha_fin    = date('Y-m-d', strtotime('+6 months'));
            
            $sqlBuy = "INSERT INTO pack_jugadores (pack_id, jugador_id, sesiones_usadas, fecha_inicio, fecha_fin) VALUES (?, ?, 0, ?, ?)";
            $stmtBuy = $conn->prepare($sqlBuy);
            $stmtBuy->bind_param("iiss", $pack_id, $jugador_id, $fecha_inicio, $fecha_fin);
            $stmtBuy->execute();
            
            $sqlCheckInsc = "SELECT id FROM inscripciones_grupales WHERE pack_id = ? AND jugador_id = ?";
            $stmtCheckInsc = $conn->prepare($sqlCheckInsc);
            $stmtCheckInsc->bind_param("ii", $pack_id, $jugador_id);
            $stmtCheckInsc->execute();
            $resCheckInsc = $stmtCheckInsc->get_result()->fetch_assoc();

            if ($resCheckInsc) {
                $sqlInsc = "UPDATE inscripciones_grupales SET estado = 'activo', fecha_inscripcion = NOW() WHERE id = ?";
                $stmtInsc = $conn->prepare($sqlInsc);
                $stmtInsc->bind_param("i", $resCheckInsc['id']);
            } else {
                $sqlInsc = "INSERT INTO inscripciones_grupales (pack_id, jugador_id, fecha_inscripcion, estado) VALUES (?, ?, NOW(), 'activo')";
                $stmtInsc = $conn->prepare($sqlInsc);
                $stmtInsc->bind_param("ii", $pack_id, $jugador_id);
            }
            $stmtInsc->execute();

            $sqlUpdate = "UPDATE packs SET cupos_ocupados = cupos_ocupados + 1 WHERE id = ?";
            $stmtUpdate = $conn->prepare($sqlUpdate);
            $stmtUpdate->bind_param("i", $pack_id);
            $stmtUpdate->execute();

            $conn->commit();
            
            // Logic for notifications could go here or in a separate step
            return true;
        } catch (Exception $e) {
            $conn->rollback();
            return false;
        }
    } else {
        // INDIVIDUAL PACK
        $fecha_inicio = date('Y-m-d');
        $fecha_fin    = date('Y-m-d', strtotime('+6 months'));

        $sql = "INSERT INTO pack_jugadores (pack_id, jugador_id, sesiones_usadas, fecha_inicio, fecha_fin, reserva_id) VALUES (?, ?, 0, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iisssi", $pack_id, $jugador_id, $fecha_inicio, $fecha_fin, $reserva_id);

        if ($stmt->execute()) {
            if ($reserva_id) {
                $stmtRes = $conn->prepare("UPDATE reservas SET estado = 'reservado', pack_id = ? WHERE id = ?");
                $stmtRes->bind_param("ii", $pack_id, $reserva_id);
                $stmtRes->execute();
            }
            return true;
        }
        return false;
    }
}
