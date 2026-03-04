<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
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


/* ========= BODY ========= */
$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["error" => "JSON inválido"]);
    exit;
}

try {

    if (!$conn) {
        throw new Exception("No hay conexión con la base de datos");
    }

    /* ========= VALIDAR CAMPOS ========= */
    $required = ['entrenador_id', 'pack_id', 'fecha', 'hora_inicio', 'hora_fin','jugador_id','estado'];
    foreach ($required as $field) {
        if (!isset($data[$field])) {
            throw new Exception("Falta el campo: $field");
        }
    }

    /* ========= VALIDAR CLASES DISPONIBLES (DESHABILITADO POR EL USUARIO) ========= */
    /*
    $recurrencia = isset($data['recurrencia']) ? max(1, intval($data['recurrencia'])) : 1;
    
    $stmtCheck = $conn->prepare("
        SELECT 
            SUM(p.sesiones_totales) AS sesiones_totales,
            COUNT(rj.reserva_id) AS clases_reservadas
        FROM 
            pack_jugadores pj
        JOIN 
            packs p ON pj.pack_id = p.id
        LEFT JOIN 
            reserva_jugadores rj ON pj.jugador_id = rj.jugador_id AND rj.reserva_id IN (
                SELECT id FROM reservas WHERE estado = 'reservado'
            )
        WHERE 
            pj.jugador_id = ?
    ");

    $stmtCheck->bind_param("i", $data['jugador_id']);
    $stmtCheck->execute();
    $resultCheck = $stmtCheck->get_result()->fetch_assoc();
    $sesiones_totales = (int)($resultCheck['sesiones_totales'] ?? 0);
    $clases_reservadas = (int)($resultCheck['clases_reservadas'] ?? 0);
    $clases_disponibles = $sesiones_totales - $clases_reservadas;

    if ($clases_disponibles < $recurrencia) {
        http_response_code(400);
        echo json_encode([
            "error" => "No tienes suficientes clases disponibles",
            "message" => "Necesitas $recurrencia sesiones, pero solo te quedan $clases_disponibles.",
            "clases_disponibles" => $clases_disponibles,
            "code" => "INSUFFICIENT_CLASSES"
        ]);
        exit;
    }
    */
    $recurrencia = isset($data['recurrencia']) ? max(1, intval($data['recurrencia'])) : 1;

    $serie_id = ($recurrencia > 1) ? uniqid('serie_') : null;
    $reservas_creadas = [];
    $errores = [];

    $conn->begin_transaction();

    try {
        for ($i = 0; $i < $recurrencia; $i++) {
            $currentDate = date('Y-m-d', strtotime($data['fecha'] . " +$i weeks"));
            
            // 0. Obtener información del pack para validar tipo y capacidad
            $stmtPack = $conn->prepare("SELECT tipo, capacidad_maxima FROM packs WHERE id = ?");
            $stmtPack->bind_param("i", $data['pack_id']);
            $stmtPack->execute();
            $packInfo = $stmtPack->get_result()->fetch_assoc();
            $tipoNuevo = $packInfo['tipo'] ?? ($data['tipo'] ?? 'individual');
            $maxCapacity = (int)($packInfo['capacidad_maxima'] ?? 1);

            // 1. Validar si el horario ya está ocupado para este entrenador
            $stmtOccupied = $conn->prepare("
                SELECT id, tipo FROM reservas 
                WHERE entrenador_id = ? 
                AND fecha = ? 
                AND hora_inicio < ? 
                AND hora_fin > ? 
                AND estado = 'reservado'
            ");
            $stmtOccupied->bind_param("isss", $data['entrenador_id'], $currentDate, $data['hora_fin'], $data['hora_inicio']);
            $stmtOccupied->execute();
            $resOccupied = $stmtOccupied->get_result();

            $countGrupal = 0;
            while ($rowOcc = $resOccupied->fetch_assoc()) {
                $tipoExistente = $rowOcc['tipo'] ?? 'individual';
                
                // Si la reserva existente o la nueva es individual, no se permite solapamiento
                if ($tipoExistente === 'individual' || $tipoNuevo === 'individual') {
                    throw new Exception("El horario ya está ocupado por una clase individual el día $currentDate a las {$data['hora_inicio']}.");
                }

                if ($tipoExistente === 'grupal') {
                    $countGrupal++;
                }
            }

            // Validar capacidad para clases grupales
            if ($tipoNuevo === 'grupal' && $countGrupal >= $maxCapacity) {
                throw new Exception("Lo sentimos, la clase grupal para este horario ya está completa ($maxCapacity alumnos).");
            }

            /* ========= INSERT RESERVA ========= */
            $stmtReserva = $conn->prepare("
                INSERT INTO reservas
                (entrenador_id, pack_id, fecha, hora_inicio, hora_fin, estado, serie_id, tipo, cantidad_personas, club_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $tipo = $data['tipo'] ?? 'individual';
            $cant = $data['cantidad_personas'] ?? 1;
            $clubId = $data['club_id'] ?? null;
            $stmtReserva->bind_param(
                "iissssssii",
                $data['entrenador_id'],
                $data['pack_id'],
                $currentDate,
                $data['hora_inicio'],
                $data['hora_fin'],
                $data['estado'],
                $serie_id,
                $tipo,
                $cant,
                $clubId
            );
            $stmtReserva->execute();
            $new_reserva_id = $conn->insert_id;

            /* ========= INSERT JUGADOR ========= */
            $stmtJugador = $conn->prepare("
                INSERT INTO reserva_jugadores (reserva_id, jugador_id)
                VALUES (?, ?)
            ");
            $stmtJugador->bind_param("ii", $new_reserva_id, $data['jugador_id']);
            $stmtJugador->execute();

            $reservas_creadas[] = $new_reserva_id;
        }

        $conn->commit();

        // --- RESPUESTA INMEDIATA ---
        echo json_encode([
            "ok" => true,
            "message" => ($recurrencia > 1) ? "Se han agendado $recurrencia clases correctamente." : "Reserva guardada correctamente",
            "reserva_ids" => $reservas_creadas,
            "serie_id" => $serie_id
        ]);
    } catch (Exception $serieError) {
        $conn->rollback();
        throw $serieError;
    }

    // Cerramos la conexión con el navegador para eliminar el lag
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    // --- NOTIFICATIONS START (Background) ---
    require_once "../notifications/whatsapp_service.php";
    require_once "../system/mail_service.php";

    // Fetch phones, emails AND names (Player and Coach)
    $sqlData = "
        SELECT 
            u1.telefono as cel_jugador, u1.nombre as nom_jugador, u1.usuario as email_jugador,
            u2.telefono as cel_entrenador, u2.nombre as nom_entrenador, u2.usuario as email_entrenador
        FROM usuarios u1 
        JOIN usuarios u2 ON u2.id = ?
        WHERE u1.id = ?
    ";

    $stmtP = $conn->prepare($sqlData);
    if ($stmtP) {
        $stmtP->bind_param("ii", $data['entrenador_id'], $data['jugador_id']);
        $stmtP->execute();
        $resP = $stmtP->get_result()->fetch_assoc();

        if ($resP) {
            $celJugador = $resP['cel_jugador'];
            $nomJugador = $resP['nom_jugador'];
            $emailJugador = $resP['email_jugador'];
            
            $celEntrenador = $resP['cel_entrenador'];
            $nomEntrenador = $resP['nom_entrenador'];
            $emailEntrenador = $resP['email_entrenador'];
            
            // Format Date and Time
            $fechaFmt = date("d/m/Y", strtotime($data['fecha']));
            $horaFmt = substr($data['hora_inicio'], 0, 5); // 00:00

            // 1. WHATSAPP
            $vars = [$fechaFmt, $horaFmt, $nomJugador, $nomEntrenador];
            if ($celJugador) enviarWhatsApp($celJugador, 'reserva_confirmada', 'es_CL', $vars); 
            if ($celEntrenador) enviarWhatsApp($celEntrenador, 'reserva_confirmada', 'es_CL', $vars);

            // 2. EMAIL
            $subject = "Reserva Confirmada - " . $fechaFmt . " " . $horaFmt;
            
            // Email content for Player
            $recurringMsg = $recurrencia > 1 ? "<p style='color: #d32f2f;'><strong>Esta es una serie de $recurrencia semanas consecutivas en este mismo horario.</strong></p>" : "";
            
            $bodyPlayer = "
            <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                <h2 style='color: #111;'>¡Tu entrenamiento está confirmado!</h2>
                <p>Hola <strong>$nomJugador</strong>,</p>
                <p>Tu reserva con el entrenador <strong>$nomEntrenador</strong> ha sido agendada con éxito.</p>
                $recurringMsg
                <div style='background: #f4f4f4; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                    <p style='margin: 5px 0;'><strong>Fecha Inicio:</strong> $fechaFmt</p>
                    <p style='margin: 5px 0;'><strong>Hora:</strong> $horaFmt</p>
                </div>
                <p>¡Nos vemos en la pista!</p>
                <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
                <p style='font-size: 12px; color: #888;'>Padel Manager - Gestión Integral de Padel</p>
            </div>";

            // Email content for Coach
            $bodyCoach = "
            <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                <h2 style='color: #111;'>Nueva Reserva Recibida</h2>
                <p>Hola <strong>$nomEntrenador</strong>,</p>
                <p>El jugador <strong>$nomJugador</strong> ha reservado una clase contigo.</p>
                $recurringMsg
                <div style='background: #f4f4f4; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                    <p style='margin: 5px 0;'><strong>Fecha Inicio:</strong> $fechaFmt</p>
                    <p style='margin: 5px 0;'><strong>Hora:</strong> $horaFmt</p>
                </div>
                <p>Revisa tu agenda para más detalles.</p>
                <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
                <p style='font-size: 12px; color: #888;'>Padel Manager - Gestión Integral de Padel</p>
            </div>";

            if (!empty($emailJugador)) enviarCorreoSMTP($emailJugador, $subject, $bodyPlayer);
            if (!empty($emailEntrenador)) enviarCorreoSMTP($emailEntrenador, $subject, $bodyCoach);

            // 3. PUSH (Save to DB)
            // Para el Entrenador
            $stmtNotifE = $conn->prepare("INSERT INTO notificaciones (user_id, titulo, mensaje, tipo, leida) VALUES (?, ?, ?, 'nueva_reserva', 0)");
            $tPushE = "Nueva Clase Agendada";
            $mPushE = $nomJugador . " ha reservado clase el " . $fechaFmt . " a las " . $horaFmt;
            $stmtNotifE->bind_param("iss", $data['entrenador_id'], $tPushE, $mPushE);
            $stmtNotifE->execute();
            $stmtNotifE->close();

            // Para el Jugador
            $stmtNotifJ = $conn->prepare("INSERT INTO notificaciones (user_id, titulo, mensaje, tipo, leida) VALUES (?, ?, ?, 'reserva_confirmada', 0)");
            $tPushJ = "Clase Confirmada";
            $mPushJ = "Tu clase con $nomEntrenador el día $fechaFmt a las $horaFmt está confirmada.";
            $stmtNotifJ->bind_param("iss", $data['jugador_id'], $tPushJ, $mPushJ);
            $stmtNotifJ->execute();
            $stmtNotifJ->close();
        }
    }
    // --- NOTIFICATIONS END ---

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "error" => $e->getMessage()
    ]);
}
