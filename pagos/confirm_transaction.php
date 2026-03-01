<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once "../db.php";

$token_ws = $_POST['token_ws'] ?? '';

// Check Cancellation
if (strpos($token_ws, 'CANCEL_') === 0) {
    header("Location: http://localhost:4200/jugador-packs?status=cancelled");
    exit;
}

// Decode Data (MOCK only strategy)
$json = base64_decode($token_ws);
$data = json_decode($json, true);

if (!$data || !isset($data['pack_id'])) {
    header("Location: http://localhost:4200/jugador-packs?status=error_token");
    exit;
}

$pack_id = (int)$data['pack_id'];
$jugador_id = (int)$data['jugador_id'];
$origin = $data['origin'] ?? 'http://localhost:4200/jugador-packs';
$reserva_id = $data['reserva_id'] ?? null;

// 1. Get Pack Details to check type
$sqlPack = "SELECT tipo, capacidad_maxima, cupos_ocupados FROM packs WHERE id = ?";
$stmtPack = $conn->prepare($sqlPack);
$stmtPack->bind_param("i", $pack_id);
$stmtPack->execute();
$resPack = $stmtPack->get_result()->fetch_assoc();

if (!$resPack) {
    header("Location: " . $origin . "?status=error_pack_not_found");
    exit;
}

$tipo = $resPack['tipo'];
$capacidad_maxima = $resPack['capacidad_maxima'];
$cupos_ocupados = $resPack['cupos_ocupados'];

// 2. Logic based on Type
if ($tipo === 'grupal') {
    // START TRANSACTION
    $conn->begin_transaction();

    try {
        // A. Insert into pack_jugadores (Record the Payment)
        $fecha_inicio = date('Y-m-d');
        $fecha_fin    = date('Y-m-d', strtotime('+6 months'));
        
        $sqlBuy = "INSERT INTO pack_jugadores (pack_id, jugador_id, sesiones_usadas, fecha_inicio, fecha_fin) VALUES (?, ?, 0, ?, ?)";
        $stmtBuy = $conn->prepare($sqlBuy);
        $stmtBuy->bind_param("iiss", $pack_id, $jugador_id, $fecha_inicio, $fecha_fin);
        $stmtBuy->execute();
        
        // B. Automatic Inscription
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
        
        if (!$stmtInsc->execute()) {
             throw new Exception("Error al inscribir: " . $stmtInsc->error);
        }

        // C. Update Capacity
        $sqlUpdate = "UPDATE packs SET cupos_ocupados = cupos_ocupados + 1 WHERE id = ?";
        $stmtUpdate = $conn->prepare($sqlUpdate);
        $stmtUpdate->bind_param("i", $pack_id);
        $stmtUpdate->execute();

        // D. Activate Group if needed
        $stmtCheck = $conn->prepare("SELECT cupos_ocupados FROM packs WHERE id = ?");
        $stmtCheck->bind_param("i", $pack_id);
        $stmtCheck->execute();
        $newCupos = $stmtCheck->get_result()->fetch_assoc()['cupos_ocupados'];

        if ($newCupos >= 4) {
             $conn->query("UPDATE packs SET estado_grupo = 'activo' WHERE id = $pack_id");
        }

        $conn->commit();
        
         // E. WhatsApp Notification
          require_once "../notifications/whatsapp_service.php";
          // Fetch More Details for Message
          $sqlMsg = "
            SELECT u.telefono, u.nombre, p.nombre as pack_nombre, p.dia_semana, p.hora_inicio, e.nombre as entrenador_nombre
            FROM usuarios u 
            JOIN packs p ON p.id = ?
            JOIN usuarios e ON e.id = p.entrenador_id
            WHERE u.id = ?
          ";
          $stmtMsg = $conn->prepare($sqlMsg);
          $stmtMsg->bind_param("ii", $pack_id, $jugador_id);
          $stmtMsg->execute();
          $resMsg = $stmtMsg->get_result()->fetch_assoc();

          if ($resMsg && $resMsg['telefono']) {
             $dias = ["Domingo", "Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado"];
             $diaFmt = isset($resMsg['dia_semana']) ? $dias[$resMsg['dia_semana']] : "Día a confirmar";
             $horaFmt  = !empty($resMsg['hora_inicio']) ? substr($resMsg['hora_inicio'], 0, 5) : "--:--";
             $vars = [$diaFmt, $horaFmt, $resMsg['nombre'], $resMsg['entrenador_nombre']];
             enviarWhatsApp($resMsg['telefono'], 'reserva_confirmada', 'es_CL', $vars);
          }

        header("Location: " . $origin . "?status=success_group");

    } catch (Exception $e) {
        $conn->rollback();
        header("Location: " . $origin . "?status=error_group_inscription");
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
            // Actualizar la reserva de 'bloqueado' a 'reservado'
            $stmtRes = $conn->prepare("UPDATE reservas SET estado = 'reservado', pack_id = ? WHERE id = ?");
            $stmtRes->bind_param("ii", $pack_id, $reserva_id);
            $stmtRes->execute();

            // Enviar notificaciones
            require_once "../notifications/whatsapp_service.php";
            $sqlMsg = "
                SELECT r.fecha, r.hora_inicio, u.telefono as cel_jugador, u.nombre as nom_jugador, 
                       e.telefono as cel_entrenador, e.nombre as nom_entrenador
                FROM reservas r
                JOIN usuarios u ON u.id = ?
                JOIN usuarios e ON e.id = r.entrenador_id
                WHERE r.id = ?
            ";
            $stmtM = $conn->prepare($sqlMsg);
            $stmtM->bind_param("ii", $jugador_id, $reserva_id);
            $stmtM->execute();
            $resM = $stmtM->get_result()->fetch_assoc();

            if ($resM) {
                $fechaFmt = date("d/m/Y", strtotime($resM['fecha']));
                $horaFmt = substr($resM['hora_inicio'], 0, 5);
                $vars = [$fechaFmt, $horaFmt, $resM['nom_jugador'], $resM['nom_entrenador']];
                if ($resM['cel_jugador']) enviarWhatsApp($resM['cel_jugador'], 'reserva_confirmada', 'es_CL', $vars);
                if ($resM['cel_entrenador']) enviarWhatsApp($resM['cel_entrenador'], 'reserva_confirmada', 'es_CL', $vars);
            }
        }
        header("Location: " . $origin . "?status=success" . ($reserva_id ? "&reserva=confirmed" : ""));
    } else {
        header("Location: " . $origin . "?status=error_db");
    }
}
exit;
