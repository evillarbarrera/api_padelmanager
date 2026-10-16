<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
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

$authUser = validateActiveSession($conn);
$userRole = $authUser['rol'] ?? 'jugador';
$authUserId = intval($authUser['id'] ?? 0);

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
            throw new Exception("Falta el campo requerido: $field");
        }
    }

    $isCoachOrAdmin = ($userRole === 'entrenador' || $userRole === 'admin' || $userRole === 'club' || ($authUserId > 0 && $authUserId === intval($data['entrenador_id'] ?? -1)));

    // VALIDACIÓN ESTRICTA: NUNCA PERMITIR RESERVAS CERO O NULAS
    if (empty($data['pack_id']) || $data['pack_id'] <= 0) {
        throw new Exception("Bloqueo de Seguridad: Toda reserva debe tener obligatoriamente un Pack asignado. No se permite pack nulo o cero.");
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
        (entrenador_id, pack_id, pack_jugador_id, fecha, hora_inicio, hora_fin, estado, serie_id, tipo, cantidad_personas, club_id, malla_id, clase_id, clase_titulo)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtJugador = $conn->prepare("
        INSERT INTO reserva_jugadores (reserva_id, jugador_id)
        VALUES (?, ?)
    ");

    // --- PACK TYPE INFO ---
    $isGroup = false;
    $pTypeInfo = null;
    if (!empty($data['pack_id']) && $data['pack_id'] > 0) {
        $stmtPT = $conn->prepare("SELECT entrenador_id, tipo FROM packs WHERE id = ?");
        $stmtPT->bind_param("i", $data['pack_id']);
        $stmtPT->execute();
        $pTypeInfo = $stmtPT->get_result()->fetch_assoc();
        $isGroup = ($pTypeInfo && ($pTypeInfo['tipo'] === 'grupal' || $pTypeInfo['tipo'] === 'pack_grupal' || $pTypeInfo['tipo'] === 'clase grupal'));
    }

    // --- CREDIT VALIDATION ---
    // If the reservation is made by the coach or admin for a group class, they are opening/scheduling the group session
    // and credit limits for individual group templates do not block coach scheduling.
    if (!$isCoachOrAdmin && !empty($data['pack_id']) && $data['pack_id'] > 0 && !empty($data['jugador_id']) && $data['jugador_id'] > 0) {
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
                          AND p2.tipo NOT IN ('grupal', 'pack_grupal', 'clase grupal')
                    ) as sesiones_totales,
                    (
                        SELECT COUNT(DISTINCT r2.id) 
                        FROM reserva_jugadores rj2 
                        JOIN reservas r2 ON rj2.reserva_id = r2.id 
                        WHERE rj2.jugador_id = ? 
                          AND r2.entrenador_id = ?
                          AND r2.estado != 'cancelado'
                          AND r2.tipo NOT IN ('grupal', 'pack_grupal', 'clase grupal')
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

    $jugadoresAInsertar = [];
    if (isset($data['jugador_ids']) && is_array($data['jugador_ids']) && count($data['jugador_ids']) > 0) {
        $jugadoresAInsertar = $data['jugador_ids'];
    } else {
        $jugadoresAInsertar[] = $data['jugador_id'];
    }

    for ($i = 0; $i < $recurrencia; $i++) {
        $currentDate = date('Y-m-d', strtotime($data['fecha'] . " +$i weeks"));

        /* 
        // --- QA VALIDATION: No permitir fechas pasadas ---
        if ($currentDate < date('Y-m-d')) {
            throw new Exception("No puedes agendar una clase en una fecha pasada ($currentDate).");
        }
        */

        // 0. Pack Info
        $stmtPack->bind_param("i", $data['pack_id']);
        $stmtPack->execute();
        $packInfo = $stmtPack->get_result()->fetch_assoc();

        $tipoNuevoRaw = strtolower($packInfo['tipo'] ?? ($data['tipo'] ?? 'individual'));
        $dataTipo = strtolower($data['tipo'] ?? 'individual');
        // Si el frontend envía explícitamente 'grupal', o si el tipo del pack contiene la palabra 'grupal', se marca como grupal.
        $tipoNuevo = ($dataTipo === 'grupal' || strpos($tipoNuevoRaw, 'grupal') !== false) ? 'grupal' : 'individual';

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

        // 2. Player Conflict (check all students being scheduled)
        foreach ($jugadoresAInsertar as $idJugadorConf) {
            if (empty($idJugadorConf) || intval($idJugadorConf) <= 0) continue;
            $stmtPlayerConflict->bind_param("isss", $idJugadorConf, $currentDate, $data['hora_fin'], $data['hora_inicio']);
            $stmtPlayerConflict->execute();
            $resPlayerConflict = $stmtPlayerConflict->get_result();

            if ($resPlayerConflict->num_rows > 0) {
                $conf = $resPlayerConflict->fetch_assoc();
                $hConf = substr($conf['hora_inicio'], 0, 5);
                $stmtPN = $conn->prepare("SELECT nombre FROM usuarios WHERE id = ?");
                $stmtPN->bind_param("i", $idJugadorConf);
                $stmtPN->execute();
                $pNameRow = $stmtPN->get_result()->fetch_assoc();
                $pName = $pNameRow['nombre'] ?? 'Un jugador';
                throw new Exception("$pName ya tiene una reserva el $currentDate a las $hConf con {$conf['entrenador_nombre']}.");
            }
        }

        // 2.5 JOIN LOGIC: Si se envió reserva_id, evitamos crear una nueva y nos unimos a la existente
        $id_reserva_previa = isset($data['reserva_id']) ? intval($data['reserva_id']) : 0;
        
        if ($id_reserva_previa > 0) {
            // Actualizar pack_id y pack_jugador_id de la reserva si están vacíos o si queremos forzar el link
            // Nota: En sesiones grupales, el pack_id de la reserva suele ser el del creador, pero aquí vinculamos
            // al menos el pack_jugador_id si es una reserva individual o queremos asegurar el rastro.
            $stmtUpd = $conn->prepare("UPDATE reservas SET pack_id = ?, pack_jugador_id = ? WHERE id = ? AND (pack_id = 0 OR pack_id IS NULL)");
            $pId = $data['pack_id'];
            $pjId = $data['pack_jugador_id'] ?? 0;
            $stmtUpd->bind_param("iii", $pId, $pjId, $id_reserva_previa);
            $stmtUpd->execute();
            
            $new_reserva_id = $id_reserva_previa;
        } else {
            // 3. Insert NEW
            $cant = $data['cantidad_personas'] ?? 1;
            $clubId = $data['club_id'] ?? null;
            $insertPackId = $data['pack_id']; 
            $insertPackJugadorId = $data['pack_jugador_id'] ?? 0;
            $mallaId = $data['malla_id'] ?? 0;
            $claseId = $data['clase_id'] ?? 0;
            $claseTitulo = $data['clase_titulo'] ?? '';

            $stmtReserva->bind_param(
                "iiissssssiiiis",
                $data['entrenador_id'],
                $insertPackId,
                $insertPackJugadorId,
                $currentDate,
                $data['hora_inicio'],
                $data['hora_fin'],
                $data['estado'],
                $serie_id,
                $tipoNuevo,
                $cant,
                $clubId,
                $mallaId,
                $claseId,
                $claseTitulo
            );
            $stmtReserva->execute();
            $new_reserva_id = $conn->insert_id;
        }

        // 3.5 AUTO-ACTIVATE RoadMap (New Improvement!)
        // If the reservation includes a mesh and there's no active follow-up for that pack, create it.
        if ($mallaId > 0 && $insertPackId > 0 && !$isGroup) {
            $checkMalla = $conn->prepare("SELECT id FROM alumno_malla_seguimiento 
                                          WHERE jugador_id = ? AND entrenador_id = ? AND pack_id = ? AND estado = 'activo' LIMIT 1");
            $checkMalla->bind_param("iii", $data['jugador_id'], $data['entrenador_id'], $insertPackId);
            $checkMalla->execute();
            $resCM = $checkMalla->get_result();

            if ($resCM->num_rows === 0) {
                // If it doesn't exist, we auto-assign it
                $insMalla = $conn->prepare("INSERT INTO alumno_malla_seguimiento (jugador_id, entrenador_id, pack_id, malla_id, estado, fecha_inicio) VALUES (?, ?, ?, ?, 'activo', NOW())");
                $insMalla->bind_param("iiii", $data['jugador_id'], $data['entrenador_id'], $insertPackId, $mallaId);
                $insMalla->execute();
            }
        }

        // --- SOPORTE MULTIJUGADOR / GRUPAL / AUTO-DÚO ---
        // 1. Prioridad Absoluta: Si el frontend envía una lista, la usamos sin importar el tipo.
        // Array ya inicializado arriba

        // 2. We skip any AUTO-DÚO backend overrides because the frontend explicitly 
        // controls and sends exactly who participates via `jugador_ids`.


        // 3. Inserción Masiva
        foreach ($jugadoresAInsertar as $idJugador) {
            if (!empty($idJugador)) {
                // Verificar si ya existe para evitar duplicados en el JOIN
                $checkRj = $conn->prepare("SELECT id FROM reserva_jugadores WHERE reserva_id = ? AND jugador_id = ?");
                $checkRj->bind_param("ii", $new_reserva_id, $idJugador);
                $checkRj->execute();
                if ($checkRj->get_result()->num_rows === 0) {
                    $stmtJugador->bind_param("ii", $new_reserva_id, $idJugador);
                    $stmtJugador->execute();
                }
            }
        }

        // 4. Actualizamos el 'cantidad_personas' real de la reserva por si el frontend envió otro
        $realCount = count($jugadoresAInsertar);
        $conn->query("UPDATE reservas SET cantidad_personas = $realCount WHERE id = $new_reserva_id");

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

    // Entrenador
    $stmtU->bind_param("i", $data['entrenador_id']);
    $stmtU->execute();
    $uEntr = $stmtU->get_result()->fetch_assoc();

    if ($uEntr) {
        $fechaFmt = date("d/m/Y", strtotime($data['fecha']));
        $horaFmt = substr($data['hora_inicio'], 0, 5);
        $nomEntrenador = $uEntr['nombre'];

        // --- BUCLE DE NOTIFICACIONES PARA TODOS LOS ALUMNOS ---
        foreach ($jugadoresAInsertar as $idActual) {
            if (empty($idActual)) continue;

            $stmtU->bind_param("i", $idActual);
            $stmtU->execute();
            $uAlum = $stmtU->get_result()->fetch_assoc();

            if ($uAlum) {
                $nomJugador = $uAlum['nombre'];
                $emailJugador = $uAlum['usuario']; // usuario is email

                // 1. Notificación App (Push/Internal)
                $msg = "Tu clase con $nomEntrenador el $fechaFmt a las $horaFmt ha sido confirmada.";
                notifyUser($conn, $idActual, "🎾 Clase Agendada", $msg, 'clase_agendada');

                // 2. WhatsApp Alumno
                if (!empty($uAlum['telefono'])) {
                    enviarWhatsApp($uAlum['telefono'], 'reserva_confirmada', 'es_CL', [$fechaFmt, $horaFmt, $nomEntrenador]);
                }

                // 3. Email Alumno
                if (!empty($emailJugador)) {
                    $subject = "🎾 Reserva Confirmada - $fechaFmt $horaFmt";
                    $body = "
                    <html>
                    <body style='margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica, Arial, sans-serif;'>
                      <table width='100%' border='0' cellspacing='0' cellpadding='0' style='background-color: #f1f5f9; padding: 40px 20px;'>
                        <tr>
                          <td align='center'>
                            <div style='max-width: 500px; width: 100%; background-color: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: 1px solid #e2e8f0;'>
                              <div style='background-color: #ccff00; padding: 40px; text-align: center; color: #000000;'>
                                <img src='https://api.padelmanager.cl/prd/assets/images/logo-padelblox.png' alt='PadelBlox' style='width: 120px; height: 120px; border-radius: 20px;' />
                                <h1 style='margin: 20px 0 0; font-size: 26px; font-weight: 900; text-transform: uppercase;'>¡CLASE CONFIRMADA!</h1>
                                <p style='margin: 5px 0 0; font-size: 14px; font-weight: 700; opacity: 0.8;'>Tu clase ha sido agendada con éxito.</p>
                              </div>
                              <div style='padding: 40px 30px; text-align: left; color: #1e293b;'>
                                <h2 style='margin: 0 0 20px; font-size: 20px; font-weight: 800; color: #000;'>¡Hola, $nomJugador!</h2>
                                <div style='background-color: #f8fafc; border-left: 5px solid #ccff00; padding: 25px; margin-bottom: 30px; border-radius: 8px;'>
                                  <p style='margin: 0 0 12px; font-size: 15px;'><strong>Entrenador:</strong> $nomEntrenador</p>
                                  <p style='margin: 0 0 12px; font-size: 15px;'><strong>Fecha:</strong> $fechaFmt</p>
                                  <p style='margin: 0; font-size: 15px;'><strong>Hora:</strong> $horaFmt</p>
                                </div>
                                <p style='font-size: 15px; color: #475569;'>¡Nos vemos en la pista!</p>
                                <div style='text-align: center; margin-bottom: 20px;'>
                                  <a href='https://www.padelblox.cl' style='display: inline-block; background-color: #000; color: #ccff00; padding: 18px 40px; text-decoration: none; font-weight: 900; font-size: 16px; border-radius: 12px;'>INGRESAR A LA APP</a>
                                </div>
                              </div>
                              <div style='background-color: #f8fafc; padding: 25px; text-align: center; border-top: 1px solid #e2e8f0;'>
                                <p style='margin: 0; font-size: 12px; color: #94a3b8;'>&copy; " . date('Y') . " PadelBlox. Todos los derechos reservados.</p>
                              </div>
                            </div>
                          </td>
                        </tr>
                      </table>
                    </body>
                    </html>";
                    enviarCorreoSMTP($emailJugador, $subject, $body);
                }
            }
        }

        // 4. Notificar al Entrenador (Solo una vez)
        $msgEntr = "Nueva reserva de " . (count($jugadoresAInsertar) > 1 ? count($jugadoresAInsertar) . " personas " : "1 persona ") . "el $fechaFmt a las $horaFmt.";
        notifyUser($conn, $data['entrenador_id'], "🎾 Nueva Reserva", $msgEntr, 'nueva_reserva');
        if (!empty($uEntr['telefono'])) {
            $nombreGrupo = count($jugadoresAInsertar) > 1 ? "Clase Grupal" : (isset($uAlum['nombre']) ? $uAlum['nombre'] : "Clase Abierta");
            enviarWhatsApp($uEntr['telefono'], 'nueva_reserva', 'es_CL', [$nombreGrupo, $fechaFmt, $horaFmt]);
        }
    }
} catch (Throwable $e) {
    if (isset($conn)) $conn->rollback();
    http_response_code(400);
    echo json_encode(["error" => $e->getMessage()]);
}
