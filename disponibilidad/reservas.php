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
require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
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

    $required = ['entrenador_id', 'pack_id', 'fecha', 'hora_inicio', 'hora_fin', 'jugador_id', 'estado'];
    foreach ($required as $field) {
        if (!isset($data[$field])) {
            throw new Exception("Falta el campo: $field");
        }
    }

    $recurrencia = isset($data['recurrencia']) ? max(1, intval($data['recurrencia'])) : 1;
    $serie_id = ($recurrencia > 1) ? uniqid('serie_') : null;
    $reservas_creadas = [];

    $conn->begin_transaction();

    /* ========= PREPARE STATEMENTS ========= */
    $stmtPack = $conn->prepare("SELECT tipo, capacidad_maxima FROM packs WHERE id = ?");
    $stmtOccupied = $conn->prepare("
        SELECT r.id, r.tipo, r.pack_id, p.tipo as pack_tipo 
        FROM reservas r 
        LEFT JOIN packs p ON p.id = r.pack_id
        WHERE r.entrenador_id = ? 
        AND r.fecha = ? 
        AND r.hora_inicio < ? 
        AND r.hora_fin > ? 
        AND r.estado = 'reservado'
    ");
    $stmtPlayerConflict = $conn->prepare("
        SELECT r.id, r.hora_inicio, r.hora_fin, u.nombre as entrenador_nombre
        FROM reservas r
        JOIN reserva_jugadores rj ON rj.reserva_id = r.id
        JOIN usuarios u ON u.id = r.entrenador_id
        WHERE rj.jugador_id = ?
        AND r.fecha = ?
        AND r.hora_inicio < ?
        AND r.hora_fin > ?
        AND r.estado = 'reservado'
    ");
    $stmtReserva = $conn->prepare("
        INSERT INTO reservas
        (entrenador_id, pack_id, fecha, hora_inicio, hora_fin, estado, serie_id, tipo, cantidad_personas, club_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtJugador = $conn->prepare("
        INSERT INTO reserva_jugadores (reserva_id, jugador_id)
        VALUES (?, ?)
    ");

    // --- CREDIT VALIDATION ---
    if ($data['pack_id'] > 0) {
        // Fetch current pack's type to decide validation scope
        $stmtPT = $conn->prepare("SELECT entrenador_id, tipo FROM packs WHERE id = ?");
        $stmtPT->bind_param("i", $data['pack_id']);
        $stmtPT->execute();
        $pTypeInfo = $stmtPT->get_result()->fetch_assoc();
        
        $isGroup = ($pTypeInfo && ($pTypeInfo['tipo'] === 'grupal' || $pTypeInfo['tipo'] === 'pack_grupal'));
        $eId = $pTypeInfo['entrenador_id'] ?? $data['entrenador_id'];

        if ($isGroup) {
            // Scope: Specific Pack (Group sessions are pack-specific)
            $stmtCredits = $conn->prepare("
                SELECT 
                    (
                        SELECT COALESCE(SUM(p2.sesiones_totales), 0)
                        FROM pack_jugadores pj2
                        JOIN packs p2 ON p2.id = pj2.pack_id
                        WHERE pj2.jugador_id = ? AND p2.id = ?
                    ) as sesiones_totales,
                    (
                        SELECT COUNT(DISTINCT r2.id) 
                        FROM reserva_jugadores rj2 
                        JOIN reservas r2 ON rj2.reserva_id = r2.id 
                        WHERE rj2.jugador_id = ? 
                          AND r2.pack_id = ?
                          AND r2.estado != 'cancelado'
                          AND r2.pack_id IN (SELECT pack_id FROM pack_jugadores WHERE jugador_id = ?)
                    ) as sesiones_usadas
            ");
            $stmtCredits->bind_param("iiiii", $data['jugador_id'], $data['pack_id'], $data['jugador_id'], $data['pack_id'], $data['jugador_id']);
        } else {
            // Scope: Entrenador (Sum of all individual packs for THIS coach)
            $stmtCredits = $conn->prepare("
                SELECT 
                    (
                        SELECT COALESCE(SUM(p2.sesiones_totales), 0)
                        FROM pack_jugadores pj2
                        JOIN packs p2 ON p2.id = pj2.pack_id
                        WHERE pj2.jugador_id = ? 
                          AND p2.entrenador_id = ?
                          AND p2.tipo NOT IN ('grupal', 'pack_grupal')
                    ) as sesiones_totales,
                    (
                        SELECT COUNT(DISTINCT r2.id) 
                        FROM reserva_jugadores rj2 
                        JOIN reservas r2 ON rj2.reserva_id = r2.id 
                        WHERE rj2.jugador_id = ? 
                          AND r2.entrenador_id = ?
                          AND r2.estado != 'cancelado'
                          AND r2.tipo NOT IN ('grupal', 'pack_grupal')
                          AND r2.pack_id IN (SELECT pack_id FROM pack_jugadores WHERE jugador_id = ?)
                    ) as sesiones_usadas
            ");
            $stmtCredits->bind_param("iiiii", $data['jugador_id'], $eId, $data['jugador_id'], $eId, $data['jugador_id']);
        }

        $stmtCredits->execute();
        $resCredits = $stmtCredits->get_result()->fetch_assoc();

        if ($resCredits) {
            $total = (int)$resCredits['sesiones_totales'];
            $used = (int)$resCredits['sesiones_usadas'];
            $disponibles = $total - $used;
            if ($recurrencia > $disponibles) {
                $showDisp = max(0, $disponibles);
                $msgError = $isGroup ? "Créditos grupales insuficientes." : "Créditos insuficientes con este entrenador.";
                throw new Exception("$msgError Tienes $showDisp disponibles (Compradas: $total, Agendadas: $used).");
            }
        }
    }

    for ($i = 0; $i < $recurrencia; $i++) {
        $currentDate = date('Y-m-d', strtotime($data['fecha'] . " +$i weeks"));
        
        // --- QA VALIDATION: No permitir fechas pasadas ---
        if ($currentDate < date('Y-m-d')) {
            throw new Exception("No puedes agendar una clase en una fecha pasada ($currentDate).");
        }
        
        // 0. Pack Info
        $stmtPack->bind_param("i", $data['pack_id']);
        $stmtPack->execute();
        $packInfo = $stmtPack->get_result()->fetch_assoc();
        
        $tipoNuevoRaw = $packInfo['tipo'] ?? ($data['tipo'] ?? 'individual');
        $tipoNuevo = ($tipoNuevoRaw === 'grupal' || $tipoNuevoRaw === 'clase grupal' || $tipoNuevoRaw === 'multiplayer') ? 'grupal' : 'individual';
        
        $defaultCap = ($tipoNuevo === 'grupal') ? 6 : 1;
        $maxCapacity = (int)($packInfo['capacidad_maxima'] ?? $defaultCap);

        // 1. Trainer occupation
        $stmtOccupied->bind_param("isss", $data['entrenador_id'], $currentDate, $data['hora_fin'], $data['hora_inicio']);
        $stmtOccupied->execute();
        $resOccupied = $stmtOccupied->get_result();

        $countGrupal = 0;
        while ($rowOcc = $resOccupied->fetch_assoc()) {
            $tipoExistente = $rowOcc['tipo'] ?? ($rowOcc['pack_tipo'] ?? 'individual');
            
            if ($tipoExistente === 'individual' || $tipoNuevo === 'individual') {
                $horaConflicto = substr($data['hora_inicio'], 0, 5);
                throw new Exception("El entrenador ya tiene una clase ($tipoExistente) el $currentDate a las $horaConflicto.");
            }
            if ($tipoExistente === 'grupal') {
                $countGrupal++;
            }
        }

        if ($tipoNuevo === 'grupal' && $countGrupal >= $maxCapacity) {
            throw new Exception("La clase grupal ya alcanzó su cupo de $maxCapacity alumnos para el $currentDate.");
        }

        // 2. Player Conflict
        $stmtPlayerConflict->bind_param("isss", $data['jugador_id'], $currentDate, $data['hora_fin'], $data['hora_inicio']);
        $stmtPlayerConflict->execute();
        $resPlayerConflict = $stmtPlayerConflict->get_result();

        if ($resPlayerConflict->num_rows > 0) {
            $conf = $resPlayerConflict->fetch_assoc();
            $hConf = substr($conf['hora_inicio'], 0, 5);
            throw new Exception("Ya tienes una reserva el $currentDate a las $hConf con {$conf['entrenador_nombre']}.");
        }

        // 3. Insert
        $cant = $data['cantidad_personas'] ?? 1;
        $clubId = $data['club_id'] ?? null;
        $stmtReserva->bind_param(
            "iissssssii",
            $data['entrenador_id'], $data['pack_id'], $currentDate,
            $data['hora_inicio'], $data['hora_fin'], $data['estado'],
            $serie_id, $tipoNuevo, $cant, $clubId
        );
        $stmtReserva->execute();
        $new_reserva_id = $conn->insert_id;

        $stmtJugador->bind_param("ii", $new_reserva_id, $data['jugador_id']);
        $stmtJugador->execute();

        $reservas_creadas[] = $new_reserva_id;
    }

    $conn->commit();

    echo json_encode([
        "ok" => true,
        "message" => "Reserva realizada con éxito",
        "reservas" => $reservas_creadas
    ]);

    // Background notifications
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    
    // Notificaciones...
    require_once "../notifications/whatsapp_service.php";
    require_once "../system/mail_service.php";
    require_once "../notifications/notificaciones_helper.php";

    $sqlData = "SELECT nombre, usuario, telefono FROM usuarios WHERE id = ?";
    $stmtU = $conn->prepare($sqlData);
    
    // Alumno
    $stmtU->bind_param("i", $data['jugador_id']);
    $stmtU->execute();
    $uAlum = $stmtU->get_result()->fetch_assoc();
    
    // Entrenador
    $stmtU->bind_param("i", $data['entrenador_id']);
    $stmtU->execute();
    $uEntr = $stmtU->get_result()->fetch_assoc();

    if ($uAlum && $uEntr) {
        $fechaFmt = date("d/m/Y", strtotime($data['fecha']));
        $horaFmt = substr($data['hora_inicio'], 0, 5);
        $nomJugador = $uAlum['nombre'];
        $nomEntrenador = $uEntr['nombre'];
        $emailJugador = $uAlum['usuario']; // usuario is email
        
        $msg = "Tu clase con $nomEntrenador el $fechaFmt a las $horaFmt ha sido confirmada.";
        notifyUser($conn, $data['jugador_id'], "🎾 Clase Agendada", $msg, 'clase_agendada');
        
        $msgEntr = "Nueva clase agendada: $nomJugador el $fechaFmt a las $horaFmt.";
        notifyUser($conn, $data['entrenador_id'], "🎾 Nueva Reserva", $msgEntr, 'nueva_reserva');

        // --- NEW: WHATSAPP ---
        require_once "../notifications/whatsapp_service.php";
        $vars = [$fechaFmt, $horaFmt, $nomJugador, $nomEntrenador];
        if (!empty($uAlum['telefono'])) {
            enviarWhatsApp($uAlum['telefono'], 'reserva_confirmada', 'es_CL', [$fechaFmt, $horaFmt, $nomEntrenador]);
        }
        if (!empty($uEntr['telefono'])) {
            enviarWhatsApp($uEntr['telefono'], 'nueva_reserva', 'es_CL', [$nomJugador, $fechaFmt, $horaFmt]);
        }

        // --- NEW: EMAIL ---
        $subject = "🎾 Reserva Confirmada - $fechaFmt $horaFmt";
        $body = "
        <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <h2 style='color: #1a73e8;'>¡Reserva Confirmada!</h2>
            <p>Hola <strong>$nomJugador</strong>,</p>
            <p>Tu clase de pádel ha sido agendada con éxito:</p>
            <ul>
                <li><strong>Entrenador:</strong> $nomEntrenador</li>
                <li><strong>Fecha:</strong> $fechaFmt</li>
                <li><strong>Hora:</strong> $horaFmt</li>
            </ul>
            <p>¡Nos vemos en la pista!</p>
            <hr style='border: 0; border-top: 1px solid #eee;'>
            <p style='font-size: 12px; color: #777;'>Padel Manager - Academia</p>
        </div>";
        
        if (!empty($emailJugador)) {
            enviarCorreoSMTP($emailJugador, $subject, $body);
        }
    }

} catch (Throwable $e) {
    if (isset($conn)) $conn->rollback();
    http_response_code(400);
    echo json_encode(["error" => $e->getMessage()]);
}
?>
