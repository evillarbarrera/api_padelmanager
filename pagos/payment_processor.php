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
            
            // === NOTIFICACIONES DE INSCRIPCION GRUPAL CONFIRMADA ===
            require_once "../notifications/whatsapp_service.php";
            require_once "../system/mail_service.php";
            require_once "../notifications/notificaciones_helper.php";

            $sqlMsgGrp = "
                SELECT u.telefono as cel_jugador, u.nombre as nom_jugador, u.usuario as email_jugador,
                       p.nombre as pack_nombre, p.dia_semana, p.hora_inicio, 
                       e.nombre as nom_entrenador, e.telefono as cel_entrenador, e.usuario as email_entrenador,
                       p.entrenador_id
                FROM usuarios u 
                JOIN packs p ON p.id = ?
                JOIN usuarios e ON e.id = p.entrenador_id
                WHERE u.id = ?
            ";
            $stmtMsgGrp = $conn->prepare($sqlMsgGrp);
            $stmtMsgGrp->bind_param("ii", $pack_id, $jugador_id);
            $stmtMsgGrp->execute();
            $resMsgGrp = $stmtMsgGrp->get_result()->fetch_assoc();

            if ($resMsgGrp) {
                $dias = ["Domingo", "Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado"];
                $diaFmt = isset($resMsgGrp['dia_semana']) ? $dias[$resMsgGrp['dia_semana']] : "Día a confirmar";
                $horaFmt  = !empty($resMsgGrp['hora_inicio']) ? substr($resMsgGrp['hora_inicio'], 0, 5) : "--:--";
                $nomJugador = $resMsgGrp['nom_jugador'];
                $nomEntrenador = $resMsgGrp['nom_entrenador'];

                // 1. WhatsApp
                $varsGrp = [$diaFmt, $horaFmt, $nomJugador, $nomEntrenador];
                if ($resMsgGrp['cel_jugador']) enviarWhatsApp($resMsgGrp['cel_jugador'], 'reserva_confirmada', 'es_CL', $varsGrp);
                if ($resMsgGrp['cel_entrenador']) enviarWhatsApp($resMsgGrp['cel_entrenador'], 'reserva_confirmada', 'es_CL', $varsGrp);

                // 2. Correo (SMTP)
                $subjectGrp = "Inscripción Grupal Confirmada - " . $diaFmt . " " . $horaFmt;
                    
                $bodyPlayerGrp = "
                <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                    <h2 style='color: #111;'>¡Inscripción Confirmada!</h2>
                    <p>Hola <strong>$nomJugador</strong>,</p>
                    <p>Tu inscripción al entrenamiento grupal con <strong>$nomEntrenador</strong> ha sido pagada y confirmada.</p>
                    <div style='background: #f4f4f4; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                        <p style='margin: 5px 0;'><strong>Día Base:</strong> $diaFmt</p>
                        <p style='margin: 5px 0;'><strong>Hora:</strong> $horaFmt</p>
                    </div>
                </div>";

                $bodyCoachGrp = "
                <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                    <h2 style='color: #111;'>Nueva Inscripción Grupal (Pagada)</h2>
                    <p>Hola <strong>$nomEntrenador</strong>,</p>
                    <p>El jugador <strong>$nomJugador</strong> se ha sumado a tu sesión grupal tras confirmar su pago.</p>
                    <div style='background: #f4f4f4; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                        <p style='margin: 5px 0;'><strong>Día Base:</strong> $diaFmt</p>
                        <p style='margin: 5px 0;'><strong>Hora:</strong> $horaFmt</p>
                    </div>
                </div>";

                if (!empty($resMsgGrp['email_jugador'])) enviarCorreoSMTP($resMsgGrp['email_jugador'], $subjectGrp, $bodyPlayerGrp);
                if (!empty($resMsgGrp['email_entrenador'])) enviarCorreoSMTP($resMsgGrp['email_entrenador'], $subjectGrp, $bodyCoachGrp);

                // 3. Push
                $tPushGrp = "Inscripción Grupal Confirmada";
                $mPushGrp = "Tu inscripción para los " . $diaFmt . " a las " . $horaFmt . " ha sido activada.";
                notifyUser($conn, $jugador_id, $tPushGrp, $mPushGrp, 'inscripcion_grupal');

                $tPushCoach = "Nuevo Alumno en Grupo";
                $mPushCoach = "$nomJugador se ha inscrito en tu grupo de los $diaFmt a las $horaFmt";
                notifyUser($conn, $resMsgGrp['entrenador_id'], $tPushCoach, $mPushCoach, 'nuevo_alumno_grupal');
            }

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

                // === NOTIFICACIONES DE RESERVA CONFIRMADA ===
                require_once "../notifications/whatsapp_service.php";
                require_once "../system/mail_service.php";
                require_once "../notifications/notificaciones_helper.php";

                $sqlMsg = "
                    SELECT r.fecha, r.hora_inicio, 
                           u.telefono as cel_jugador, u.nombre as nom_jugador, u.usuario as email_jugador,
                           e.telefono as cel_entrenador, e.nombre as nom_entrenador, e.usuario as email_entrenador,
                           r.entrenador_id
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
                    $nomJugador = $resM['nom_jugador'];
                    $nomEntrenador = $resM['nom_entrenador'];
                    $entrenadorId = $resM['entrenador_id'];

                    // 1. WhatsApp
                    $vars = [$fechaFmt, $horaFmt, $nomJugador, $nomEntrenador];
                    if ($resM['cel_jugador']) enviarWhatsApp($resM['cel_jugador'], 'reserva_confirmada', 'es_CL', $vars);
                    if ($resM['cel_entrenador']) enviarWhatsApp($resM['cel_entrenador'], 'reserva_confirmada', 'es_CL', $vars);

                    // 2. Correo (SMTP)
                    $subject = "Reserva Confirmada - " . $fechaFmt . " " . $horaFmt;
                    
                    $bodyPlayer = "
                    <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                        <h2 style='color: #111;'>¡Tu entrenamiento está confirmado (Pago verificado)!</h2>
                        <p>Hola <strong>$nomJugador</strong>,</p>
                        <p>Tu reserva con el entrenador <strong>$nomEntrenador</strong> ha sido agendada con éxito tras la verificación del pago.</p>
                        <div style='background: #f4f4f4; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                            <p style='margin: 5px 0;'><strong>Fecha:</strong> $fechaFmt</p>
                            <p style='margin: 5px 0;'><strong>Hora:</strong> $horaFmt</p>
                        </div>
                        <p>¡Nos vemos en la pista!</p>
                        <p style='font-size: 12px; color: #888;'>Padel Manager</p>
                    </div>";

                    $bodyCoach = "
                    <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                        <h2 style='color: #111;'>Nueva Reserva Recibida (Pagada)</h2>
                        <p>Hola <strong>$nomEntrenador</strong>,</p>
                        <p>El jugador <strong>$nomJugador</strong> ha adquirido un pack pagado y reservado una clase contigo.</p>
                        <div style='background: #f4f4f4; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                            <p style='margin: 5px 0;'><strong>Fecha:</strong> $fechaFmt</p>
                            <p style='margin: 5px 0;'><strong>Hora:</strong> $horaFmt</p>
                        </div>
                        <p>Revisa tu agenda para más detalles.</p>
                        <p style='font-size: 12px; color: #888;'>Padel Manager</p>
                    </div>";

                    if (!empty($resM['email_jugador'])) enviarCorreoSMTP($resM['email_jugador'], $subject, $bodyPlayer);
                    if (!empty($resM['email_entrenador'])) enviarCorreoSMTP($resM['email_entrenador'], $subject, $bodyCoach);

                    // 3. Notificación Push Interna
                    $tPushE = "Nueva Clase Agendada";
                    $mPushE = $nomJugador . " ha reservado clase el " . $fechaFmt . " a las " . $horaFmt;
                    notifyUser($conn, $entrenadorId, $tPushE, $mPushE, 'nueva_reserva');

                    $tPushJ = "Clase Confirmada";
                    $mPushJ = "Tu clase con $nomEntrenador el día $fechaFmt a las $horaFmt está confirmada.";
                    notifyUser($conn, $jugador_id, $tPushJ, $mPushJ, 'reserva_confirmada');
                }
            }
            return true;
        }
        return false;
    }
}
