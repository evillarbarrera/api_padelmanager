<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Access-Control-Max-Age: 86400");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$headers = getallheaders();
require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}

try {
    require_once "../db.php";

    // Leer el body de la solicitud
    $body = file_get_contents("php://input");
    $data = json_decode($body, true);
    
    // Validar que se recibieron los datos
    if (!$data || !is_array($data)) {
        http_response_code(400);
        echo json_encode([
            "error" => "JSON inválido",
            "body" => $body,
            "decoded" => $data
        ]);
        exit;
    }
    
    $reserva_id = isset($data['reserva_id']) ? intval($data['reserva_id']) : 0;
    $jugador_id = isset($data['jugador_id']) ? intval($data['jugador_id']) : 0;

    if ($reserva_id <= 0 || $jugador_id <= 0) {
        http_response_code(400);
        echo json_encode([
            "error" => "reserva_id y jugador_id son obligatorios y deben ser números válidos",
            "reserva_id" => $reserva_id,
            "jugador_id" => $jugador_id,
            "data" => $data
        ]);
        exit;
    }

    // 1. Obtener información de la reserva
    $query = "SELECT r.id, r.fecha, r.hora_inicio, r.estado FROM reservas r ";
    $query .= "JOIN reserva_jugadores rj ON r.id = rj.reserva_id ";
    $query .= "WHERE r.id = ? AND rj.jugador_id = ?";
    
    $stmtReserva = $conn->prepare($query);
    
    if (!$stmtReserva) {
        throw new Exception("Error prepare: " . $conn->error);
    }
    
    $stmtReserva->bind_param("ii", $reserva_id, $jugador_id);
    
    if (!$stmtReserva->execute()) {
        throw new Exception("Error execute: " . $stmtReserva->error);
    }
    
    $result = $stmtReserva->get_result();
    $reserva = $result->fetch_assoc();
    
    if (!$reserva) {
        // Fallback: Verificar si es una reserva de cancha en 'reservas_cancha'
        $stmtCancha = $conn->prepare("SELECT id, fecha, hora_inicio, hora_fin, estado, usuario_id, jugador2_id, jugador3_id, jugador4_id, liga_partido_id FROM reservas_cancha WHERE id = ? AND (usuario_id = ? OR jugador2_id = ? OR jugador3_id = ? OR jugador4_id = ?)");
        if ($stmtCancha) {
            $stmtCancha->bind_param("iiiii", $reserva_id, $jugador_id, $jugador_id, $jugador_id, $jugador_id);
            $stmtCancha->execute();
            $resC = $stmtCancha->get_result()->fetch_assoc();
            if ($resC) {
                if (strtolower($resC['estado']) === 'cancelada' || strtolower($resC['estado']) === 'cancelado') {
                    http_response_code(400);
                    echo json_encode(["error" => "Esta reserva de cancha ya fue cancelada"]);
                    exit;
                }

                if ($resC['usuario_id'] == $jugador_id) {
                    $conn->query("UPDATE reservas_cancha SET estado = 'Cancelada', venta_generada = 0, pagado = 0 WHERE id = $reserva_id");
                    if (!empty($resC['liga_partido_id'])) {
                        $lpId = intval($resC['liga_partido_id']);
                        $conn->query("UPDATE liga_partidos SET cancha_id = NULL, fecha_hora = NULL WHERE id = $lpId");
                    }
                    try {
                        require_once "../system/mail_service.php";
                        notificarReservaCancha($conn, $reserva_id, 'cancelacion');
                    } catch (Throwable $e) {}
                    echo json_encode(["success" => true, "message" => "Reserva de cancha cancelada exitosamente"]);
                    exit;
                } else {
                    $col = ($resC['jugador2_id'] == $jugador_id) ? 'jugador2_id' : (($resC['jugador3_id'] == $jugador_id) ? 'jugador3_id' : 'jugador4_id');
                    $conn->query("UPDATE reservas_cancha SET $col = NULL WHERE id = $reserva_id");
                    echo json_encode(["success" => true, "message" => "Has salido de la reserva de cancha"]);
                    exit;
                }
            }
        }

        http_response_code(404);
        echo json_encode(["error" => "Reserva no encontrada"]);
        exit;
    }
    
    // 2. Validar que no esté cancelada
    if ($reserva['estado'] === 'cancelado') {
        http_response_code(400);
        echo json_encode(["error" => "Esta reserva ya fue cancelada"]);
        exit;
    }
    
    // 3. Validar las 12 horas de anticipación
    $fecha_hora_reserva = new DateTime($reserva['fecha'] . ' ' . $reserva['hora_inicio']);
    $ahora = new DateTime();
    
    // Calcular diferencia en segundos y convertir a horas
    $timestamp_reserva = $fecha_hora_reserva->getTimestamp();
    $timestamp_ahora = $ahora->getTimestamp();
    $diferencia_segundos = $timestamp_reserva - $timestamp_ahora;
    $horas_restantes = $diferencia_segundos / 3600;
    
    if ($horas_restantes < 8) {
        http_response_code(400);
        echo json_encode([
            "error" => "No puedes cancelar con menos de 8 horas de anticipación",
            "horas_restantes" => max(0, floor($horas_restantes)),
            "code" => "INSUFFICIENT_TIME"
        ]);
        exit;
    }
    
    // 4. Lógica de cancelación inteligente
    // Contar cuántos jugadores hay en la reserva
    $stmtCount = $conn->prepare("SELECT COUNT(*) as total FROM reserva_jugadores WHERE reserva_id = ?");
    $stmtCount->bind_param("i", $reserva_id);
    $stmtCount->execute();
    $countRes = $stmtCount->get_result()->fetch_assoc();
    $totalJugadores = $countRes['total'] ?? 0;

    // Obtener tipo de reserva y otros detalles
    $stmtInfo = $conn->prepare("SELECT tipo, estado FROM reservas WHERE id = ?");
    $stmtInfo->bind_param("i", $reserva_id);
    $stmtInfo->execute();
    $resInfo = $stmtInfo->get_result()->fetch_assoc();
    $tipo = strtolower($resInfo['tipo'] ?? 'individual');

    // Definir si es una reserva de "múltiples cupos" (Grupal, Multi, etc.)
    $esGrupal = (
        $totalJugadores > 1 || 
        $tipo === 'grupal' || 
        $tipo === 'pack_grupal' || 
        $tipo === 'clase grupal' || 
        $tipo === 'multijugador' || 
        $tipo === 'duo' || 
        $tipo === 'trio'
    );

    $soloRemoverJugador = false;
    if ($esGrupal) {
        $soloRemoverJugador = true;
    }

    if ($soloRemoverJugador) {
        // --- SOLO QUITAR AL JUGADOR ---
        $stmtDel = $conn->prepare("DELETE FROM reserva_jugadores WHERE reserva_id = ? AND jugador_id = ?");
        $stmtDel->bind_param("ii", $reserva_id, $jugador_id);
        if (!$stmtDel->execute()) {
            throw new Exception("Error al remover jugador: " . $stmtDel->error);
        }
        
        // Si no es un "producto" grupal fijo, decrementamos el contador de personas
        if ($tipo !== 'grupal' && $tipo !== 'pack_grupal' && $tipo !== 'clase grupal') {
            $conn->query("UPDATE reservas SET cantidad_personas = cantidad_personas - 1 WHERE id = $reserva_id");
        }
        
        $msgFinal = "Has sido removido de la clase correctamente.";
    } else {
        // --- CANCELAR TODA LA RESERVA (Solo para individuales con 1 solo alumno) ---
        $stmtCancel = $conn->prepare("UPDATE reservas SET estado = 'cancelado' WHERE id = ?");
        $stmtCancel->bind_param("i", $reserva_id);
        if (!$stmtCancel->execute()) {
            throw new Exception("Error al cancelar reserva: " . $stmtCancel->error);
        }
        $msgFinal = "Reserva cancelada correctamente.";
    }

    // --- SEND RESPONSE IMMEDIATELY ---
    echo json_encode([
        "ok" => true,
        "message" => $msgFinal,
        "reserva_id" => $reserva_id,
        "modo" => $soloRemoverJugador ? "remover_jugador" : "cancelar_total"
    ]);

    // Flush and close connection if possible
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    // --- NOTIFICATIONS START (Background Processing) ---
    require_once "../notifications/whatsapp_service.php";
    require_once "../system/mail_service.php";
    require_once "../notifications/notificaciones_helper.php";
    
    // Fetch phones, emails AND names
    $sqlData = "
        SELECT 
            u1.id as jugador_id, u1.telefono as cel_jugador, u1.nombre as nom_jugador, u1.usuario as email_jugador,
            u2.id as entrenador_id, u2.telefono as cel_entrenador, u2.nombre as nom_entrenador, u2.usuario as email_entrenador
        FROM usuarios u1 
        JOIN reservas r ON r.id = ?
        JOIN usuarios u2 ON u2.id = r.entrenador_id
        WHERE u1.id = ?
    ";

    $stmtP = $conn->prepare($sqlData);
    if ($stmtP) {
        $stmtP->bind_param("ii", $reserva_id, $jugador_id);
        $stmtP->execute();
        $resP = $stmtP->get_result()->fetch_assoc();
        
        if ($resP) {
            $nomJugador = $resP['nom_jugador'];
            $nomEntrenador = $resP['nom_entrenador'];
            $emailJugador = $resP['email_jugador'];
            $emailEntrenador = $resP['email_entrenador'];
            
            $fechaFmt = date("d/m/Y", strtotime($reserva['fecha']));
            $horaFmt = substr($reserva['hora_inicio'], 0, 5);

            // 1. WHATSAPP
            $vars = [$fechaFmt, $horaFmt, $nomJugador, $nomEntrenador];
            if ($resP['cel_jugador']) enviarWhatsApp($resP['cel_jugador'], 'reserva_cancelada', 'es_CL', $vars); 
            if ($resP['cel_entrenador']) enviarWhatsApp($resP['cel_entrenador'], 'reserva_cancelada', 'es_CL', $vars);

            // 2. EMAIL
            $subject = "🚫 Reserva Cancelada - " . $fechaFmt . " " . $horaFmt;
            $bodyPlayer = "
            <html><body style='margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica, Arial, sans-serif;'>
              <table width='100%' border='0' cellspacing='0' cellpadding='0' style='background-color: #f1f5f9; padding: 40px 20px;'><tr><td align='center'>
                <div style='max-width: 500px; width: 100%; background-color: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: 1px solid #e2e8f0;'>
                  <div style='background-color: #ef4444; padding: 40px; text-align: center; color: #ffffff;'>
                    <img src='https://api.padelmanager.cl/assets/images/logo-padelblox.png' alt='PadelBlox' style='width: 120px; height: 120px; border-radius: 20px;' />
                    <h1 style='margin: 20px 0 0; font-size: 26px; font-weight: 900; text-transform: uppercase;'>RESERVA CANCELADA</h1>
                  </div>
                  <div style='padding: 40px 30px; text-align: left; color: #1e293b;'>
                    <h2 style='margin: 0 0 20px; font-size: 20px; font-weight: 800;'>Hola, $nomJugador</h2>
                    <p style='font-size: 15px;'>Tu reserva para el entrenamiento con <strong>$nomEntrenador</strong> ha sido cancelada.</p>
                    <div style='background-color: #f8fafc; border-left: 5px solid #ef4444; padding: 25px; margin: 20px 0; border-radius: 8px;'>
                      <p style='margin: 0 0 12px; font-size: 15px;'><strong>Fecha:</strong> $fechaFmt</p>
                      <p style='margin: 0; font-size: 15px;'><strong>Hora:</strong> $horaFmt</p>
                    </div>
                    <div style='text-align: center; margin: 20px 0;'><a href='https://www.padelblox.cl' style='display: inline-block; background-color: #000; color: #ccff00; padding: 18px 40px; text-decoration: none; font-weight: 900; border-radius: 12px;'>INGRESAR A LA APP</a></div>
                  </div>
                  <div style='background-color: #f8fafc; padding: 25px; text-align: center; border-top: 1px solid #e2e8f0;'><p style='margin: 0; font-size: 12px; color: #94a3b8;'>&copy; " . date('Y') . " PadelBlox. Todos los derechos reservados.</p></div>
                </div>
              </td></tr></table>
            </body></html>";
            $bodyCoach = "
            <html><body style='margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica, Arial, sans-serif;'>
              <table width='100%' border='0' cellspacing='0' cellpadding='0' style='background-color: #f1f5f9; padding: 40px 20px;'><tr><td align='center'>
                <div style='max-width: 500px; width: 100%; background-color: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: 1px solid #e2e8f0;'>
                  <div style='background-color: #ef4444; padding: 40px; text-align: center; color: #ffffff;'>
                    <img src='https://api.padelmanager.cl/assets/images/logo-padelblox.png' alt='PadelBlox' style='width: 120px; height: 120px; border-radius: 20px;' />
                    <h1 style='margin: 20px 0 0; font-size: 26px; font-weight: 900; text-transform: uppercase;'>CLASE CANCELADA</h1>
                  </div>
                  <div style='padding: 40px 30px; text-align: left; color: #1e293b;'>
                    <h2 style='margin: 0 0 20px; font-size: 20px; font-weight: 800;'>Hola, $nomEntrenador</h2>
                    <p style='font-size: 15px;'>El jugador <strong>$nomJugador</strong> ha cancelado su asistencia.</p>
                    <div style='background-color: #f8fafc; border-left: 5px solid #ef4444; padding: 25px; margin: 20px 0; border-radius: 8px;'>
                      <p style='margin: 0 0 12px; font-size: 15px;'><strong>Fecha:</strong> $fechaFmt</p>
                      <p style='margin: 0; font-size: 15px;'><strong>Hora:</strong> $horaFmt</p>
                    </div>
                    <div style='text-align: center; margin: 20px 0;'><a href='https://www.padelblox.cl' style='display: inline-block; background-color: #000; color: #ccff00; padding: 18px 40px; text-decoration: none; font-weight: 900; border-radius: 12px;'>INGRESAR A LA APP</a></div>
                  </div>
                  <div style='background-color: #f8fafc; padding: 25px; text-align: center; border-top: 1px solid #e2e8f0;'><p style='margin: 0; font-size: 12px; color: #94a3b8;'>&copy; " . date('Y') . " PadelBlox. Todos los derechos reservados.</p></div>
                </div>
              </td></tr></table>
            </body></html>";

            if (!empty($emailJugador)) enviarCorreoSMTP($emailJugador, $subject, $bodyPlayer);
            if (!empty($emailEntrenador)) enviarCorreoSMTP($emailEntrenador, $subject, $bodyCoach);

            // 3. PUSH
            $idEntrenador = $resP['entrenador_id'] ?? $reserva['entrenador_id'];
            notifyUser($conn, $idEntrenador, "🚫 Clase Cancelada por Alumno", "$nomJugador ha cancelado la clase del $fechaFmt a las $horaFmt", 'clase_cancelada');
            notifyUser($conn, $jugador_id, "🚫 Cancelación Confirmada", "Has cancelado tu clase del $fechaFmt a las $horaFmt.", 'cancelacion_confirmada');
        }
    }
    // --- NOTIFICATIONS END ---

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}

if (isset($conn)) {
    $conn->close();
}
?>
