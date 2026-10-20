<?php
/**
 * Servicio de envío de correos vía API Transaccional Oficial de RESEND.COM
 */

function enviarCorreoSMTP($to, $subject, $bodyHTML) {
    @set_time_limit(300);
    $apiKey = getenv('RESEND_API_KEY') ?: '';
    $logFile = __DIR__ . '/mail_debug.log';
    $recipients = is_array($to) ? $to : [$to];
    $cleanRecipients = [];
    foreach ($recipients as $r) {
        $r = trim($r);
        if (!empty($r) && strpos($r, '@') !== false && !in_array($r, $cleanRecipients)) {
            $cleanRecipients[] = $r;
        }
    }

    if (empty($cleanRecipients)) {
        file_put_contents($logFile, date('[Y-m-d H:i:s]') . " [RESEND CANCELADO] No hay destinatarios válidos recibidos.\n", FILE_APPEND);
        return ['success' => false, 'error' => 'No valid recipients'];
    }

    $errors = [];
    $successes = 0;
    $totalRecipients = count($cleanRecipients);

    file_put_contents($logFile, date('[Y-m-d H:i:s]') . " [RESEND API INICIO] Despachando a $totalRecipients cuentas vía API Transaccional Resend.com...\n", FILE_APPEND);

    foreach ($cleanRecipients as $index => $singleTo) {
        $startTime = microtime(true);
        $payload = [
            "from" => "PADELBLOX <no_reply@padelmanager.cl>",
            "to" => [$singleTo],
            "subject" => $subject,
            "html" => $bodyHTML
        ];

        $ch = curl_init("https://api.resend.com/emails");
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $duration = round(microtime(true) - $startTime, 3);

        if ($httpCode >= 200 && $httpCode < 300) {
            $successes++;
            $logMsg = date('[Y-m-d H:i:s]') . " [RESEND API OK " . ($index+1) . "/$totalRecipients] -> <$singleTo> (Tiempo: {$duration}s, Resend: $response)\n";
        } else {
            $errors[] = "$singleTo: HTTP $httpCode - $response - $error";
            $logMsg = date('[Y-m-d H:i:s]') . " [RESEND API ERROR " . ($index+1) . "/$totalRecipients] -> <$singleTo>: Code $httpCode - $response ($error)\n";
        }
        file_put_contents($logFile, $logMsg, FILE_APPEND);
    }

    return ['success' => ($successes > 0), 'errors' => $errors];
}

function notificarReservaCancha($conn, $reserva_id, $action, $newPlayerIds = []) {
    $debugLog = __DIR__ . '/mail_debug.log';
    file_put_contents($debugLog, date('[Y-m-d H:i:s]') . " ===== INICIO NOTIFICACION RESERVA #$reserva_id (Accion: $action) =====\n", FILE_APPEND);

    // PASO 1: Obtener datos completos de la reserva
    $reservaRes = $conn->query("SELECT r.*, 
                                COALESCE(c.nombre, 'Cancha') as cancha_nombre, 
                                COALESCE(NULLIF(c.club_id, 0), 17) as club_id, 
                                COALESCE(cl.nombre, 'PowerPadel Machali') as club_nombre, 
                                cl.email as club_email 
                                FROM reservas_cancha r 
                                LEFT JOIN canchas c ON r.cancha_id = c.id 
                                LEFT JOIN clubes cl ON c.club_id = cl.id 
                                WHERE r.id = " . intval($reserva_id));
    
    $reserva = $reservaRes ? $reservaRes->fetch_assoc() : null;
    if (!$reserva) {
        file_put_contents($debugLog, date('[Y-m-d H:i:s]') . " [PASO 1 ERROR] No se encontró la Reserva #$reserva_id en la base de datos.\n", FILE_APPEND);
        return false;
    }

    $club_id = intval($reserva['club_id']);
    file_put_contents($debugLog, date('[Y-m-d H:i:s]') . " [PASO 1 DATOS] Reserva #$reserva_id -> Club: {$reserva['club_nombre']} (ID: $club_id), Cancha: {$reserva['cancha_nombre']}, Fecha: {$reserva['fecha']}, Hora: {$reserva['hora_inicio']}-{$reserva['hora_fin']}, Titular ID: " . ($reserva['usuario_id'] ?? 'NULL') . "\n", FILE_APPEND);

    $getMail = function($row) {
        if (!empty($row['usuario']) && strpos($row['usuario'], '@') !== false) {
            return trim($row['usuario']);
        }
        if (!empty($row['email']) && strpos($row['email'], '@') !== false) {
            return trim($row['email']);
        }
        return null;
    };

    $hasEmailCol = false;
    $checkCol = $conn->query("SHOW COLUMNS FROM usuarios LIKE 'email'");
    if ($checkCol && $checkCol->num_rows > 0) {
        $hasEmailCol = true;
    }
    $userFields = $hasEmailCol ? "nombre, usuario, email" : "nombre, usuario";
    
    $targets = [];

    // --- NUEVA LÓGICA DE DESTINATARIOS ---
    if ($action === 'creacion') {
        // Enviar a los administradores del club
        try {
            $linkedStaff = $conn->query("SELECT u.$userFields FROM usuarios u 
                                         JOIN usuarios_clubes uc ON u.id = uc.usuario_id 
                                         WHERE uc.club_id = $club_id AND (uc.activo = 1 OR uc.activo IS NULL)");
            if ($linkedStaff && $linkedStaff instanceof mysqli_result) {
                while ($row = $linkedStaff->fetch_assoc()) {
                    $mail = $getMail($row);
                    if ($mail) {
                        $targets[$mail] = $row['nombre'];
                        file_put_contents($debugLog, date('[Y-m-d H:i:s]') . " [CREACION STAFF] ID -> {$row['nombre']} <$mail>\n", FILE_APPEND);
                    }
                }
            }
        } catch (Throwable $e1) {}

        try {
            $ownerRes = $conn->query("SELECT u.$userFields FROM clubes c 
                                      JOIN usuarios u ON c.admin_id = u.id 
                                      LEFT JOIN usuarios_clubes uc ON uc.club_id = c.id AND uc.usuario_id = u.id
                                      WHERE c.id = $club_id AND (uc.activo = 1 OR uc.activo IS NULL)");
            if ($ownerRes && $ownerRes instanceof mysqli_result && $owner = $ownerRes->fetch_assoc()) {
                $mail = $getMail($owner);
                if ($mail) {
                    $targets[$mail] = $owner['nombre'];
                    file_put_contents($debugLog, date('[Y-m-d H:i:s]') . " [CREACION OWNER] Owner -> {$owner['nombre']} <$mail>\n", FILE_APPEND);
                }
            }
        } catch (Throwable $e2) {}

        try {
            $adminUsers = $conn->query("SELECT u.$userFields FROM usuarios u 
                                         JOIN usuarios_clubes uc ON u.id = uc.usuario_id 
                                         WHERE uc.club_id = $club_id 
                                           AND (uc.activo = 1 OR uc.activo IS NULL)
                                           AND (uc.rol IN ('administrador_club', 'administrador') OR u.rol IN ('administrador_club', 'administrador'))");
            if ($adminUsers && $adminUsers instanceof mysqli_result) {
                while ($adm = $adminUsers->fetch_assoc()) {
                    $mail = $getMail($adm);
                    if ($mail) {
                        $targets[$mail] = $adm['nombre'];
                        file_put_contents($debugLog, date('[Y-m-d H:i:s]') . " [CREACION ADMIN] Admin -> {$adm['nombre']} <$mail>\n", FILE_APPEND);
                    }
                }
            }
        } catch (Throwable $e3) {}

        // Enviar al jugador que realizó la reserva (usuario_id)
        $titularId = intval($reserva['usuario_id']);
        if ($titularId > 0) {
            $uRes = $conn->query("SELECT $userFields FROM usuarios WHERE id = $titularId");
            if ($uRes && $u = $uRes->fetch_assoc()) {
                $mail = $getMail($u);
                if ($mail) {
                    $targets[$mail] = $u['nombre'];
                    file_put_contents($debugLog, date('[Y-m-d H:i:s]') . " [CREACION TITULAR] ID $titularId -> {$u['nombre']} <$mail>\n", FILE_APPEND);
                }
            }
        }
    } 
    elseif ($action === 'actualizacion') {
        // Enviar solo al jugador nuevo si existe
        if (empty($newPlayerIds)) {
            file_put_contents($debugLog, date('[Y-m-d H:i:s]') . " [FIN ADVERTENCIA] No hay nuevos jugadores agregados en la actualización. No se enviará correo.\n", FILE_APPEND);
            return true;
        }
        
        foreach ($newPlayerIds as $npId) {
            $npId = intval($npId);
            if ($npId > 0) {
                $uRes = $conn->query("SELECT $userFields FROM usuarios WHERE id = $npId");
                if ($uRes && $u = $uRes->fetch_assoc()) {
                    $mail = $getMail($u);
                    if ($mail) {
                        $targets[$mail] = $u['nombre'];
                        file_put_contents($debugLog, date('[Y-m-d H:i:s]') . " [ACTUALIZACION NUEVO JUGADOR] ID $npId -> {$u['nombre']} <$mail>\n", FILE_APPEND);
                    }
                }
            }
        }
    } 
    elseif ($action === 'cancelacion') {
        // Enviar a todos los jugadores vinculados a la reserva
        $player_ids = [$reserva['usuario_id'], $reserva['jugador2_id'], $reserva['jugador3_id'], $reserva['jugador4_id']];
        foreach ($player_ids as $pid) {
            $pid = intval($pid);
            if ($pid > 0) {
                $uRes = $conn->query("SELECT $userFields FROM usuarios WHERE id = $pid");
                if ($uRes && $u = $uRes->fetch_assoc()) {
                    $mail = $getMail($u);
                    if ($mail) {
                        $targets[$mail] = $u['nombre'];
                        file_put_contents($debugLog, date('[Y-m-d H:i:s]') . " [CANCELACION JUGADOR] ID $pid -> {$u['nombre']} <$mail>\n", FILE_APPEND);
                    }
                }
            }
        }

        // Enviar a los administradores del club
        try {
            $linkedStaff = $conn->query("SELECT u.$userFields FROM usuarios u 
                                         JOIN usuarios_clubes uc ON u.id = uc.usuario_id 
                                         WHERE uc.club_id = $club_id AND (uc.activo = 1 OR uc.activo IS NULL)");
            if ($linkedStaff && $linkedStaff instanceof mysqli_result) {
                while ($row = $linkedStaff->fetch_assoc()) {
                    $mail = $getMail($row);
                    if ($mail) {
                        $targets[$mail] = $row['nombre'];
                        file_put_contents($debugLog, date('[Y-m-d H:i:s]') . " [CANCELACION STAFF] ID -> {$row['nombre']} <$mail>\n", FILE_APPEND);
                    }
                }
            }
        } catch (Throwable $e1) {}

        try {
            $ownerRes = $conn->query("SELECT u.$userFields FROM clubes c 
                                      JOIN usuarios u ON c.admin_id = u.id 
                                      LEFT JOIN usuarios_clubes uc ON uc.club_id = c.id AND uc.usuario_id = u.id
                                      WHERE c.id = $club_id AND (uc.activo = 1 OR uc.activo IS NULL)");
            if ($ownerRes && $ownerRes instanceof mysqli_result && $owner = $ownerRes->fetch_assoc()) {
                $mail = $getMail($owner);
                if ($mail) {
                    $targets[$mail] = $owner['nombre'];
                    file_put_contents($debugLog, date('[Y-m-d H:i:s]') . " [CANCELACION OWNER] Owner -> {$owner['nombre']} <$mail>\n", FILE_APPEND);
                }
            }
        } catch (Throwable $e2) {}

        try {
            $adminUsers = $conn->query("SELECT u.$userFields FROM usuarios u 
                                         JOIN usuarios_clubes uc ON u.id = uc.usuario_id 
                                         WHERE uc.club_id = $club_id 
                                           AND (uc.activo = 1 OR uc.activo IS NULL)
                                           AND (uc.rol IN ('administrador_club', 'administrador') OR u.rol IN ('administrador_club', 'administrador'))");
            if ($adminUsers && $adminUsers instanceof mysqli_result) {
                while ($adm = $adminUsers->fetch_assoc()) {
                    $mail = $getMail($adm);
                    if ($mail) {
                        $targets[$mail] = $adm['nombre'];
                        file_put_contents($debugLog, date('[Y-m-d H:i:s]') . " [CANCELACION ADMIN] Admin -> {$adm['nombre']} <$mail>\n", FILE_APPEND);
                    }
                }
            }
        } catch (Throwable $e3) {}
    }

    // Agregar correos de club adicionales para creación o cancelación
    if ($action === 'creacion' || $action === 'cancelacion') {
        if ($club_id == 17 || strpos(strtolower($reserva['club_nombre']), 'power') !== false) {
            $targets['admin@powerpadel.cl'] = "Administración PowerPadel";
        }

        try {
            $clubNotifRes = $conn->query("SELECT email, email_notificaciones FROM clubes WHERE id = $club_id");
            if ($clubNotifRes && $cRow = $clubNotifRes->fetch_assoc()) {
                if (!empty($cRow['email_notificaciones']) && strpos($cRow['email_notificaciones'], '@') !== false) {
                    $targets[trim($cRow['email_notificaciones'])] = "Notificaciones " . $reserva['club_nombre'];
                }
                if (!empty($cRow['email']) && strpos($cRow['email'], '@') !== false) {
                    $targets[trim($cRow['email'])] = "Administración " . $reserva['club_nombre'];
                }
            }
        } catch (Throwable $eNotif) {}
    }

    file_put_contents($debugLog, date('[Y-m-d H:i:s]') . " [PASO 6 MAPA FINAL] Destinatarios totales (" . count($targets) . "): " . json_encode($targets, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

    if (empty($targets)) {
        file_put_contents($debugLog, date('[Y-m-d H:i:s]') . " [FIN ADVERTENCIA] No hay destinatarios configurados para la Reserva #$reserva_id.\n", FILE_APPEND);
        return false;
    }

    // Resolver lista completa de jugadores para el cuerpo del correo
    $player_ids_all = [$reserva['usuario_id'], $reserva['jugador2_id'], $reserva['jugador3_id'], $reserva['jugador4_id']];
    $guest_names_all = [$reserva['nombre_externo'], $reserva['nombre_externo2'], $reserva['nombre_externo3'], $reserva['nombre_externo4']];
    $players_all = [];
    for ($i = 0; $i < 4; $i++) {
        $pid = $player_ids_all[$i];
        if (!empty($pid)) {
            $uRes = $conn->query("SELECT nombre FROM usuarios WHERE id = " . intval($pid));
            if ($uRes && $u = $uRes->fetch_assoc()) {
                $players_all[] = $u['nombre'];
            }
        } else {
            $guest = $guest_names_all[$i];
            if (!empty($guest)) {
                $players_all[] = $guest;
            }
        }
    }
    $playersListStr = implode(", ", $players_all);
    if (empty($playersListStr)) {
        $playersListStr = "Invitado";
    }

    $fechaFmt = date("d/m/Y", strtotime($reserva['fecha']));
    $horaFmt = substr($reserva['hora_inicio'], 0, 5) . " - " . substr($reserva['hora_fin'], 0, 5);
    $cancha_nombre = $reserva['cancha_nombre'];
    $club_nombre = $reserva['club_nombre'];
    $precio = number_format($reserva['precio'], 0, ',', '.');
    $estado_pago = intval($reserva['pagado']) === 1 ? 'PAGADA' : 'PENDIENTE / DEBE';
    $isEntrenamiento = (strtolower($reserva['tipo_reserva'] ?? '') === 'entrenamiento');

    if ($action === 'creacion') {
        if ($isEntrenamiento) {
            $subject = "🎾 Nueva Clase en Cancha - $fechaFmt $cancha_nombre ($club_nombre)";
            $title = "¡Nueva Clase / Entrenamiento en Cancha!";
            $sub = "Un entrenador ha reservado esta cancha para impartir una clase a sus alumnos.";
        } else {
            $subject = "🎾 Reserva Confirmada - $fechaFmt $cancha_nombre";
            $title = "¡Reserva Confirmada!";
            $sub = "Detalles de la nueva reserva de cancha.";
        }
        $themeColor = "#ccff00";
        $textColor = "#000000";
    } elseif ($action === 'actualizacion') {
        $subject = "🎾 Te han añadido a una Reserva - $fechaFmt $cancha_nombre";
        $title = "¡Te han añadido a una Reserva!";
        $sub = "Has sido agregado a una reserva de cancha.";
        $themeColor = "#ccff00";
        $textColor = "#000000";
    } else {
        $subject = ($isEntrenamiento ? "🚫 Clase en Cancha Cancelada" : "🚫 Reserva Cancelada") . " - $fechaFmt $cancha_nombre";
        $title = $isEntrenamiento ? "Clase en Cancha Cancelada" : "Reserva Cancelada";
        $sub = $isEntrenamiento ? "El entrenador ha cancelado la clase. La pista ha quedado liberada." : "Una reserva de cancha ha sido eliminada.";
        $themeColor = "#ef4444";
        $textColor = "#ffffff";
    }

    $emailList = array_keys($targets);
    $bodyHTML = "
    <html>
    <body style='margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif;'>
      <table width='100%' border='0' cellspacing='0' cellpadding='0' style='background-color: #f1f5f9; padding: 40px 20px;'>
        <tr>
          <td align='center'>
            <div style='max-width: 500px; width: 100%; background-color: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: 1px solid #e2e8f0;'>
              <div style='background-color: $themeColor; padding: 40px; text-align: center; color: $textColor;'>
                " . ($action === 'creacion' 
                  ? "<div style='display: inline-block; background-color: #0f172a; border: 2px solid #ccff00; padding: 12px 28px; border-radius: 16px; margin-bottom: 12px; box-shadow: 0 8px 20px rgba(0,0,0,0.25);'><span style='font-size: 26px; font-weight: 900; color: #ffffff; letter-spacing: -0.5px;'>Padel</span><span style='font-size: 26px; font-weight: 900; color: #ccff00; letter-spacing: -0.5px;'>Blox</span></div>" 
                  : "<div style='display: inline-block; background-color: #0f172a; border: 2px solid #ef4444; padding: 12px 28px; border-radius: 16px; margin-bottom: 12px; box-shadow: 0 8px 20px rgba(0,0,0,0.25);'><span style='font-size: 26px; font-weight: 900; color: #ffffff; letter-spacing: -0.5px;'>Padel</span><span style='font-size: 26px; font-weight: 900; color: #ef4444; letter-spacing: -0.5px;'>Blox</span></div>") . "
                <h1 style='margin: 20px 0 0; font-size: 26px; font-weight: 900; text-transform: uppercase;'>$title</h1>
                <p style='margin: 5px 0 0; font-size: 14px; font-weight: 700; opacity: 0.8;'>$sub</p>
              </div>
              <div style='padding: 40px 30px; text-align: left; color: #1e293b;'>
                <h2 style='margin: 0 0 20px; font-size: 20px; font-weight: 800; color: #000;'>¡Hola!</h2>
                
                <div style='background-color: #f8fafc; border-left: 5px solid $themeColor; padding: 25px; margin-bottom: 30px; border-radius: 8px;'>
                  <p style='margin: 0 0 12px; font-size: 15px;'><strong>Sede:</strong> $club_nombre</p>
                  <p style='margin: 0 0 12px; font-size: 15px;'><strong>Cancha:</strong> $cancha_nombre</p>
                  <p style='margin: 0 0 12px; font-size: 15px;'><strong>Fecha:</strong> $fechaFmt</p>
                  <p style='margin: 0 0 12px; font-size: 15px;'><strong>Horario:</strong> $horaFmt</p>
                  <p style='margin: 0 0 12px; font-size: 15px;'><strong>" . ($isEntrenamiento ? "Detalle Clase / Alumnos" : "Jugadores") . ":</strong> $playersListStr</p>
                  " . ($isEntrenamiento 
                    ? "<p style='margin: 0; font-size: 15px;'><strong>Modalidad:</strong> <span style='color: #0284c7; font-weight: 800;'>Clase / Entrenamiento de Pádel</span></p>" 
                    : "<p style='margin: 0 0 12px; font-size: 15px;'><strong>Precio:</strong> $$precio</p><p style='margin: 0; font-size: 15px;'><strong>Estado Pago:</strong> <strong style='color: " . ($estado_pago === 'PAGADA' ? '#16a34a' : '#dc2626') . ";'>$estado_pago</strong></p>") . "
                </div>

                <div style='text-align: center; margin-bottom: 20px;'>
                  <a href='https://www.padelblox.cl' style='display: inline-block; background-color: #000; color: #ccff00; padding: 18px 40px; text-decoration: none; font-weight: 900; font-size: 16px; border-radius: 12px; box-shadow: 0 10px 20px rgba(0,0,0,0.1);'>INGRESAR A LA APP</a>
                </div>
              </div>
              <div style='background-color: #f8fafc; padding: 25px; text-align: center; border-top: 1px solid #e2e8f0;'>
                <p style='margin: 0; font-size: 12px; color: #94a3b8;'>
                  &copy; " . date('Y') . " PADELBLOX. Todos los derechos reservados.
                </p>
              </div>
            </div>
          </td>
        </tr>
      </table>
    </body>
    </html>";

    file_put_contents($debugLog, date('[Y-m-d H:i:s]') . " [PASO 7 DISPARANDO RESEND API] Envitando a " . count($emailList) . " cuentas: " . implode(", ", $emailList) . "\n", FILE_APPEND);
    
    $res = enviarCorreoSMTP($emailList, $subject, $bodyHTML);
    
    // --- ENVÍO DE NOTIFICACIONES PUSH AL CELULAR Y PROGRAMACIÓN DE RECORDATORIOS ---
    try {
        require_once __DIR__ . '/../notifications/notificaciones_helper.php';
        
        // Asegurar columnas en recordatorios_programados para compatibilidad
        try {
            $checkColRes = $conn->query("SHOW COLUMNS FROM recordatorios_programados LIKE 'reserva_id'");
            if ($checkColRes && $checkColRes->num_rows === 0) {
                $conn->query("ALTER TABLE recordatorios_programados ADD COLUMN reserva_id INT NULL");
            }
            $checkColEnv = $conn->query("SHOW COLUMNS FROM recordatorios_programados LIKE 'enviado_at'");
            if ($checkColEnv && $checkColEnv->num_rows === 0) {
                $conn->query("ALTER TABLE recordatorios_programados ADD COLUMN enviado_at DATETIME NULL");
            }
        } catch (Throwable $eSchema) {}

        // 1. Eliminar recordatorios programados pendientes de esta reserva (evita duplicados o envíos en reservas canceladas)
        $conn->query("DELETE FROM recordatorios_programados WHERE reserva_id = " . intval($reserva_id) . " AND enviado = 0");
        
        $horaInicioFmt = substr($reserva['hora_inicio'], 0, 5);
        $fechaFmtPart = date("d/m/Y", strtotime($reserva['fecha']));
        $fechaPartido = $reserva['fecha'] . ' ' . $reserva['hora_inicio'];

        // Programar recordatorio de 2 horas antes si es una creación o actualización
        if ($action === 'creacion' || $action === 'actualizacion') {
            try {
                $matchTime = new DateTime($fechaPartido);
                $reminderTime = clone $matchTime;
                $reminderTime->modify('-2 hours');
                $now = new DateTime();
                
                if ($reminderTime > $now) {
                    $fechaProgStr = $reminderTime->format('Y-m-d H:i:s');
                    $titRecordatorio = "⏰ ¡Tu partido en 2 horas!";
                    $msgRecordatorio = "Recuerda tu partido en $club_nombre ($cancha_nombre) hoy a las $horaInicioFmt hrs. ¡Prepárate!";
                    $tipoRecordatorio = "recordatorio_partido_2h";
                    
                    $stmtReminder = $conn->prepare("INSERT INTO recordatorios_programados (user_id, titulo, mensaje, tipo, fecha_programada, reserva_id, enviado) VALUES (?, ?, ?, ?, ?, ?, 0)");
                    if ($stmtReminder) {
                        $jugadoresRem = [
                            intval($reserva['usuario_id']),
                            intval($reserva['jugador2_id']),
                            intval($reserva['jugador3_id']),
                            intval($reserva['jugador4_id'])
                        ];
                        foreach ($jugadoresRem as $jrId) {
                            if ($jrId > 0) {
                                $stmtReminder->bind_param("issssi", $jrId, $titRecordatorio, $msgRecordatorio, $tipoRecordatorio, $fechaProgStr, $reserva_id);
                                $stmtReminder->execute();
                            }
                        }
                        $stmtReminder->close();
                    }
                }
            } catch (Throwable $eRem) {
                error_log("Error programando recordatorio de 2 horas: " . $eRem->getMessage());
            }
        }

        // 2. Notificaciones push inmediatas
        if ($action === 'creacion') {
            // Notificar al titular
            $titularId = intval($reserva['usuario_id']);
            if ($titularId > 0) {
                $msgTitular = "Tu reserva en $club_nombre ($cancha_nombre) para el $fechaFmtPart a las $horaInicioFmt hrs ha sido confirmada.";
                notifyUser($conn, $titularId, "🎾 Reserva Confirmada", $msgTitular, 'reserva_confirmada');
            }

            // Notificar a los otros jugadores agregados
            $otrosJugadores = [
                intval($reserva['jugador2_id']),
                intval($reserva['jugador3_id']),
                intval($reserva['jugador4_id'])
            ];
            foreach ($otrosJugadores as $ojId) {
                if ($ojId > 0) {
                    $msgOJ = "Te han agregado a una reserva en $club_nombre ($cancha_nombre) para el $fechaFmtPart a las $horaInicioFmt hrs.";
                    notifyUser($conn, $ojId, "🎾 Nueva Reserva", $msgOJ, 'reserva_confirmada');
                }
            }
        } 
        elseif ($action === 'actualizacion') {
            // Notificar solo a los nuevos jugadores agregados
            if (!empty($newPlayerIds)) {
                foreach ($newPlayerIds as $npId) {
                    $npId = intval($npId);
                    if ($npId > 0) {
                        $msgNP = "Te han agregado a una reserva en $club_nombre ($cancha_nombre) para el $fechaFmtPart a las $horaInicioFmt hrs.";
                        notifyUser($conn, $npId, "🎾 Nueva Reserva", $msgNP, 'reserva_confirmada');
                    }
                }
            }
        } 
        elseif ($action === 'cancelacion') {
            // Notificar a todos los jugadores inscritos
            $todosJugadores = [
                intval($reserva['usuario_id']),
                intval($reserva['jugador2_id']),
                intval($reserva['jugador3_id']),
                intval($reserva['jugador4_id'])
            ];
            foreach ($todosJugadores as $tjId) {
                if ($tjId > 0) {
                    $msgCancel = "La reserva en $club_nombre ($cancha_nombre) para el $fechaFmtPart a las $horaInicioFmt hrs ha sido cancelada.";
                    notifyUser($conn, $tjId, "🚫 Reserva Cancelada", $msgCancel, 'reserva_cancelada');
                }
            }
        }
    } catch (Throwable $ePush) {
        error_log("Error enviando notificacion push de reserva: " . $ePush->getMessage());
    }

    file_put_contents($debugLog, date('[Y-m-d H:i:s]') . " ===== FIN NOTIFICACION RESERVA #$reserva_id =====\n\n", FILE_APPEND);
    return true;
}
?>
