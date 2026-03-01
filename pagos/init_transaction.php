<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$input = json_decode(file_get_contents("php://input"), true);
$pack_id = $input['pack_id'] ?? 0;
$jugador_id = $input['jugador_id'] ?? 0;
$amount = $input['amount'] ?? 0;
$origin = $input['origin'] ?? 'http://localhost:4200/jugador-packs'; // Default to Web
$reserva_id = $input['reserva_id'] ?? null;

if (!$pack_id || !$jugador_id) {
    http_response_code(400);
    echo json_encode(["error" => "Datos invalidos"]);
    exit;
}

// 1. Get Pack Details to check type and capacity
$sqlPack = "SELECT tipo, capacidad_maxima, cupos_ocupados FROM packs WHERE id = ?";
$stmtPack = $conn->prepare($sqlPack);
$stmtPack->bind_param("i", $pack_id);
$stmtPack->execute();
$resPack = $stmtPack->get_result()->fetch_assoc();

if (!$resPack) {
    http_response_code(404);
    echo json_encode(["error" => "Pack no encontrado"]);
    exit;
}

$tipo = $resPack['tipo'];
$capacidad_maxima = $resPack['capacidad_maxima'];
$cupos_ocupados = $resPack['cupos_ocupados'];

// 2. Logic for Group Packs
if ($tipo === 'grupal') {
    if ($cupos_ocupados >= $capacidad_maxima) {
        http_response_code(400);
        echo json_encode(["error" => "Lo sentimos, no quedan cupos disponibles para este entrenamiento."]);
        exit;
    }

    $conn->begin_transaction();

    try {
        // --- CREDIT REUSE LOGIC START ---
        // Buscamos si el usuario tiene un 'crédito' disponible (un pack grupal pagado pero sin inscripción activa)
        $sqlCredit = "
            SELECT pj.id, pj.pack_id 
            FROM pack_jugadores pj
            JOIN packs p ON pj.pack_id = p.id
            LEFT JOIN inscripciones_grupales ig 
                   ON ig.pack_id = pj.pack_id 
                   AND ig.jugador_id = pj.jugador_id 
                   AND ig.estado = 'activo'
            WHERE pj.jugador_id = ? 
              AND p.tipo = 'grupal'
              AND ig.id IS NULL
            LIMIT 1
        ";
        $stmtCredit = $conn->prepare($sqlCredit);
        $stmtCredit->bind_param("i", $jugador_id);
        $stmtCredit->execute();
        $resCredit = $stmtCredit->get_result()->fetch_assoc();

        $using_credit = false;

        if ($resCredit) {
            // REUTILIZAR CRÉDITO
            $old_pj_id = $resCredit['id'];
            $using_credit = true;

            // 1. Actualizamos el pack_jugadores para que apunte al NUEVO pack (transferimos el pago)
            // Esto es opcional, pero mantiene la coherencia de "qué pagaste".
            $sqlUpdatePJ = "UPDATE pack_jugadores SET pack_id = ? WHERE id = ?";
            $stmtUPJ = $conn->prepare($sqlUpdatePJ);
            $stmtUPJ->bind_param("ii", $pack_id, $old_pj_id);
            $stmtUPJ->execute();
        } else {
            // COMPRA NUEVA (No hay crédito, se crea nuevo registro de pago)
            $fecha_inicio = date('Y-m-d');
            $fecha_fin    = date('Y-m-d', strtotime('+6 months'));
            
            $sqlBuy = "INSERT INTO pack_jugadores (pack_id, jugador_id, sesiones_usadas, fecha_inicio, fecha_fin) VALUES (?, ?, 0, ?, ?)";
            $stmtBuy = $conn->prepare($sqlBuy);
            $stmtBuy->bind_param("iiss", $pack_id, $jugador_id, $fecha_inicio, $fecha_fin);
            $stmtBuy->execute();
        }
        // --- CREDIT REUSE LOGIC END ---

        // C. Automatic Inscription (The "Reservation")
        // Check if there is an existing inscription (cancelled or otherwise) to reuse or insert new.
        $sqlCheckInsc = "SELECT id FROM inscripciones_grupales WHERE pack_id = ? AND jugador_id = ?";
        $stmtCheckInsc = $conn->prepare($sqlCheckInsc);
        $stmtCheckInsc->bind_param("ii", $pack_id, $jugador_id);
        $stmtCheckInsc->execute();
        $resCheckInsc = $stmtCheckInsc->get_result()->fetch_assoc();

        if ($resCheckInsc) {
            // Existe un registro previo (ej: cancelado), lo reactivamos
            $sqlInsc = "UPDATE inscripciones_grupales SET estado = 'activo', fecha_inscripcion = NOW() WHERE id = ?";
            $stmtInsc = $conn->prepare($sqlInsc);
            $stmtInsc->bind_param("i", $resCheckInsc['id']);
        } else {
            // No existe, insertamos nuevo
            $sqlInsc = "INSERT INTO inscripciones_grupales (pack_id, jugador_id, fecha_inscripcion, estado) VALUES (?, ?, NOW(), 'activo')";
            $stmtInsc = $conn->prepare($sqlInsc);
            $stmtInsc->bind_param("ii", $pack_id, $jugador_id);
        }

        if (!$stmtInsc->execute()) {
             throw new Exception("Error al inscribir en el grupo: " . $stmtInsc->error);
        }

        // D. Update Capacity
        $sqlUpdate = "UPDATE packs SET cupos_ocupados = cupos_ocupados + 1 WHERE id = ?";
        $stmtUpdate = $conn->prepare($sqlUpdate);
        $stmtUpdate->bind_param("i", $pack_id);
        $stmtUpdate->execute();

        // E. Check if group is now "active" (>= 4 players)
        $stmtCheck = $conn->prepare("SELECT cupos_ocupados FROM packs WHERE id = ?");
        $stmtCheck->bind_param("i", $pack_id);
        $stmtCheck->execute();
        $newCupos = $stmtCheck->get_result()->fetch_assoc()['cupos_ocupados'];

        if ($newCupos >= 4) {
             $conn->query("UPDATE packs SET estado_grupo = 'activo' WHERE id = $pack_id");
        }

        $conn->commit();
        
        // F. Send WhatsApp Confirmation
        require_once "../notifications/whatsapp_service.php";
        // Fetch data for message
        $sqlMsg = "
            SELECT u.telefono as cel_jugador, u.nombre as nom_jugador, 
                   e.telefono as cel_entrenador, e.nombre as nom_entrenador,
                   p.dia_semana, p.hora_inicio
            FROM usuarios u 
            JOIN packs p ON p.id = ?
            JOIN usuarios e ON e.id = p.entrenador_id
            WHERE u.id = ?
        ";
        $stmtM = $conn->prepare($sqlMsg);
        $stmtM->bind_param("ii", $pack_id, $jugador_id);
        $stmtM->execute();
        $resM = $stmtM->get_result()->fetch_assoc();

        if ($resM) {
            $dias = ["Domingo", "Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado"];
            $diaFmt = isset($resM['dia_semana']) ? $dias[$resM['dia_semana']] : "Día a confirmar";
            $horaFmt  = !empty($resM['hora_inicio']) ? substr($resM['hora_inicio'], 0, 5) : "--:--";
            $vars = [$diaFmt, $horaFmt, $resM['nom_jugador'], $resM['nom_entrenador']];
            
            if ($resM['cel_jugador']) enviarWhatsApp($resM['cel_jugador'], 'reserva_confirmada', 'es_CL', $vars);
            if ($resM['cel_entrenador']) enviarWhatsApp($resM['cel_entrenador'], 'reserva_confirmada', 'es_CL', $vars);
        }
        $msgSuccess = $using_credit 
            ? "¡Inscripción exitosa! Has utilizado un crédito pendiente." 
            : "Compra e inscripción confirmada.";
            
        echo json_encode(["success" => true, "message" => $msgSuccess, "cupos_restantes" => ($capacidad_maxima - $newCupos)]);

    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(["error" => "Error al procesar la inscripción grupal: " . $e->getMessage()]);
    }

} else {
    // 3. Logic for Individual Packs (Original)
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
        echo json_encode(["success" => true, "message" => "Pack individual comprado exitosamente" . ($reserva_id ? " y reserva confirmada" : "")]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Error al registrar la compra"]);
    }
}
