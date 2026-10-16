<?php
/**
 * Servicio de Notificaciones por Correo y Push para Partidos de Liga
 */

require_once __DIR__ . '/../system/mail_service.php';

if (!function_exists('notificarPartidoLiga')) {
    function notificarPartidoLiga($conn, $partido_id, $tipo_evento = 'programado') {
        $partido_id = (int)$partido_id;
        if (!$partido_id) return false;

        $debugLog = __DIR__ . '/../system/mail_debug.log';

        // 1. Obtener información completa del partido, liga, categoría, grupo, cancha y club
        $sql = "SELECT p.id as partido_id, p.fecha_hora, p.cancha_id, p.estado,
                       p.pareja1_id, p.pareja2_id,
                       j.numero_jornada, COALESCE(j.nombre, CONCAT('Jornada ', j.numero_jornada)) as jornada_nombre,
                       cat.id as categoria_id, cat.nombre as categoria_nombre, cat.genero as categoria_genero,
                       g.id as grupo_id, g.nombre as grupo_nombre,
                       l.id as liga_id, l.nombre as liga_nombre, l.club_id,
                       COALESCE(c.nombre, 'Cancha por asignar') as cancha_nombre,
                       COALESCE(cl.nombre, 'Club de Pádel') as club_nombre,
                       par1.nombre_pareja as p1_nombre, par1.nombre_externo1 as p1_ext1, par1.nombre_externo2 as p1_ext2,
                       par1.jugador1_id as p1_j1_id, par1.jugador2_id as p1_j2_id,
                       par2.nombre_pareja as p2_nombre, par2.nombre_externo1 as p2_ext1, par2.nombre_externo2 as p2_ext2,
                       par2.jugador1_id as p2_j1_id, par2.jugador2_id as p2_j2_id,
                       u11.nombre as u11_nom, COALESCE(u11.apellido, '') as u11_ape, COALESCE(u11.usuario, u11.email) as u11_email,
                       u12.nombre as u12_nom, COALESCE(u12.apellido, '') as u12_ape, COALESCE(u12.usuario, u12.email) as u12_email,
                       u21.nombre as u21_nom, COALESCE(u21.apellido, '') as u21_ape, COALESCE(u21.usuario, u21.email) as u21_email,
                       u22.nombre as u22_nom, COALESCE(u22.apellido, '') as u22_ape, COALESCE(u22.usuario, u22.email) as u22_email
                FROM liga_partidos p
                LEFT JOIN liga_jornadas j ON p.jornada_id = j.id
                LEFT JOIN liga_categorias cat ON (j.categoria_id = cat.id OR p.categoria_id = cat.id)
                LEFT JOIN ligas l ON cat.liga_id = l.id
                LEFT JOIN liga_grupos g ON p.grupo_id = g.id
                LEFT JOIN canchas c ON p.cancha_id = c.id
                LEFT JOIN clubes cl ON c.club_id = cl.id OR l.club_id = cl.id
                LEFT JOIN liga_parejas par1 ON p.pareja1_id = par1.id
                LEFT JOIN liga_parejas par2 ON p.pareja2_id = par2.id
                LEFT JOIN usuarios u11 ON par1.jugador1_id = u11.id
                LEFT JOIN usuarios u12 ON par1.jugador2_id = u12.id
                LEFT JOIN usuarios u21 ON par2.jugador1_id = u21.id
                LEFT JOIN usuarios u22 ON par2.jugador2_id = u22.id
                WHERE p.id = $partido_id
                LIMIT 1";

        $res = $conn->query($sql);
        if (!$res || $res->num_rows === 0) {
            file_put_contents($debugLog, date('[Y-m-d H:i:s]') . " [LIGA NOTIF ERROR] Partido #$partido_id no encontrado en BD.\n", FILE_APPEND);
            return false;
        }

        $row = $res->fetch_assoc();

        if (empty($row['fecha_hora'])) {
            file_put_contents($debugLog, date('[Y-m-d H:i:s]') . " [LIGA NOTIF SKIP] Partido #$partido_id aún no tiene fecha_hora asignada.\n", FILE_APPEND);
            return false;
        }

        // Formateo de Nombres de Parejas
        $n11 = trim($row['u11_nom'] . ' ' . $row['u11_ape']);
        if (empty($n11)) $n11 = !empty($row['p1_ext1']) ? $row['p1_ext1'] : 'Jugador 1';
        $n12 = trim($row['u12_nom'] . ' ' . $row['u12_ape']);
        if (empty($n12)) $n12 = !empty($row['p1_ext2']) ? $row['p1_ext2'] : 'Jugador 2';
        $pareja1_nombre = !empty($row['p1_nombre']) ? $row['p1_nombre'] : "$n11 / $n12";

        $n21 = trim($row['u21_nom'] . ' ' . $row['u21_ape']);
        if (empty($n21)) $n21 = !empty($row['p2_ext1']) ? $row['p2_ext1'] : 'Jugador 1';
        $n22 = trim($row['u22_nom'] . ' ' . $row['u22_ape']);
        if (empty($n22)) $n22 = !empty($row['p2_ext2']) ? $row['p2_ext2'] : 'Jugador 2';
        $pareja2_nombre = !empty($row['p2_nombre']) ? $row['p2_nombre'] : "$n21 / $n22";

        // Recolectar destinatarios de correo válidos
        $emailTargets = [];
        $playerUserIds = [];

        $addEmail = function($email, $name, $userId = null) use (&$emailTargets, &$playerUserIds) {
            $email = trim((string)$email);
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emailTargets[$email] = $name;
            }
            if (!empty($userId) && (int)$userId > 0) {
                $playerUserIds[] = (int)$userId;
            }
        };

        $addEmail($row['u11_email'], $n11, $row['p1_j1_id']);
        $addEmail($row['u12_email'], $n12, $row['p1_j2_id']);
        $addEmail($row['u21_email'], $n21, $row['p2_j1_id']);
        $addEmail($row['u22_email'], $n22, $row['p2_j2_id']);

        $playerUserIds = array_unique($playerUserIds);

        // Formato de Fechas y Horas en Español
        $diasEs = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
        $mesesEs = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

        $timestamp = strtotime($row['fecha_hora']);
        $diaSemana = $diasEs[(int)date('w', $timestamp)];
        $diaNum = date('d', $timestamp);
        $mesNom = $mesesEs[(int)date('n', $timestamp)];
        $anoNum = date('Y', $timestamp);
        $fechaTexto = "$diaSemana $diaNum de $mesNom, $anoNum";

        $horaInicio = date('H:i', $timestamp);
        $horaFin = date('H:i', $timestamp + 5400); // 90 minutos de juego
        $horarioTexto = "$horaInicio a $horaFin hrs (90 min de juego)";

        $ligaNombre = !empty($row['liga_nombre']) ? $row['liga_nombre'] : 'Liga de Pádel';
        $categoriaNombre = !empty($row['categoria_nombre']) ? $row['categoria_nombre'] : 'Categoría General';
        if (!empty($row['categoria_genero'])) {
            $categoriaNombre .= " ({$row['categoria_genero']})";
        }
        $grupoNombre = !empty($row['grupo_nombre']) ? $row['grupo_nombre'] : '';
        $jornadaNombre = !empty($row['jornada_nombre']) ? $row['jornada_nombre'] : 'Jornada';
        $canchaNombre = !empty($row['cancha_nombre']) ? $row['cancha_nombre'] : 'Cancha Principal';
        $clubNombre = !empty($row['club_nombre']) ? $row['club_nombre'] : 'PadelBlox';

        // Estilos y Temática según Acción
        if ($tipo_evento === 'reprogramado') {
            $subject = "⚠️ Reprogramación de Partido de Liga: $pareja1_nombre vs $pareja2_nombre — $diaNum/$mesNom $horaInicio hrs";
            $bannerTitle = "Modificación de Partido";
            $bannerSub = "Se ha actualizado la fecha, horario o cancha de tu partido de liga.";
            $themeColor = "#f59e0b";
            $textColor = "#000000";
            $badgeBg = "#fef3c7";
            $badgeColor = "#b45309";
        } else {
            $subject = "🎾 Partido de Liga Asignado: $pareja1_nombre vs $pareja2_nombre — $diaNum/$mesNom $horaInicio hrs";
            $bannerTitle = "¡Tu Partido de Liga está Programado!";
            $bannerSub = "Revisa a continuación los detalles de tu próximo encuentro oficial.";
            $themeColor = "#ccff00";
            $textColor = "#000000";
            $badgeBg = "#ecfccb";
            $badgeColor = "#3f6212";
        }

        $bodyHTML = "
        <!DOCTYPE html>
        <html lang='es'>
        <head>
          <meta charset='UTF-8'>
          <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        </head>
        <body style='margin: 0; padding: 0; background-color: #0b0f19; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; color: #1e293b;'>
          <table width='100%' border='0' cellspacing='0' cellpadding='0' style='background-color: #0b0f19; padding: 40px 15px;'>
            <tr>
              <td align='center'>
                <div style='max-width: 580px; width: 100%; background-color: #ffffff; border-radius: 24px; overflow: hidden; box-shadow: 0 20px 40px rgba(0,0,0,0.5); border: 1px solid #1e293b;'>
                  
                  <!-- HEADER OFICIAL -->
                  <div style='background-color: #0f172a; padding: 36px 30px; text-align: center; border-bottom: 4px solid $themeColor;'>
                    <div style='display: inline-block; background: linear-gradient(135deg, #1e293b, #0f172a); border: 2px solid $themeColor; padding: 8px 24px; border-radius: 50px; margin-bottom: 14px;'>
                      <span style='font-size: 22px; font-weight: 900; color: #ffffff; letter-spacing: -0.5px;'>Padel</span><span style='font-size: 22px; font-weight: 900; color: #ccff00; letter-spacing: -0.5px;'>Blox</span>
                    </div>
                    <div style='font-size: 13px; font-weight: 800; color: $themeColor; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 4px;'>
                      🏆 $ligaNombre
                    </div>
                    <h1 style='margin: 8px 0 0; font-size: 22px; font-weight: 900; color: #ffffff; text-transform: uppercase; letter-spacing: 0.5px;'>
                      $bannerTitle
                    </h1>
                    <p style='margin: 6px 0 0; font-size: 14px; font-weight: 500; color: #94a3b8;'>
                      $bannerSub
                    </p>
                  </div>

                  <!-- CONTENIDO PRINCIPAL -->
                  <div style='padding: 32px 28px;'>
                    
                    <!-- BADGE DE FASE Y CATEGORIA -->
                    <div style='text-align: center; margin-bottom: 24px;'>
                      <span style='display: inline-block; background-color: $badgeBg; color: $badgeColor; font-size: 13px; font-weight: 800; padding: 6px 16px; border-radius: 20px; text-transform: uppercase;'>
                        $jornadaNombre " . (!empty($grupoNombre) ? " • $grupoNombre" : "") . " • $categoriaNombre
                      </span>
                    </div>

                    <!-- CARD DEL PARTIDO (MATCHUP) -->
                    <div style='background: #f8fafc; border: 2px solid #e2e8f0; border-radius: 18px; padding: 22px; margin-bottom: 24px; text-align: center;'>
                      <div style='font-size: 12px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 12px;'>Encuentro Oficial</div>
                      <table width='100%' border='0' cellspacing='0' cellpadding='0'>
                        <tr>
                          <td width='44%' align='center' style='vertical-align: middle;'>
                            <div style='font-size: 16px; font-weight: 900; color: #0f172a; line-height: 1.3;'>$pareja1_nombre</div>
                            <div style='font-size: 12px; font-weight: 600; color: #64748b; margin-top: 4px;'>$n11 / $n12</div>
                          </td>
                          <td width='12%' align='center' style='vertical-align: middle;'>
                            <span style='display: inline-block; background: #0f172a; color: #ccff00; font-size: 12px; font-weight: 900; padding: 6px 10px; border-radius: 10px;'>VS</span>
                          </td>
                          <td width='44%' align='center' style='vertical-align: middle;'>
                            <div style='font-size: 16px; font-weight: 900; color: #0f172a; line-height: 1.3;'>$pareja2_nombre</div>
                            <div style='font-size: 12px; font-weight: 600; color: #64748b; margin-top: 4px;'>$n21 / $n22</div>
                          </td>
                        </tr>
                      </table>
                    </div>

                    <!-- DETALLES DE CITACIÓN (HORARIO Y SEDE) -->
                    <div style='background: #f1f5f9; border-left: 6px solid $themeColor; border-radius: 12px; padding: 20px 24px; margin-bottom: 28px;'>
                      <table width='100%' border='0' cellspacing='0' cellpadding='6'>
                        <tr>
                          <td width='28' style='font-size: 18px;'>📅</td>
                          <td style='font-size: 15px; color: #334155;'><strong>Fecha:</strong> $fechaTexto</td>
                        </tr>
                        <tr>
                          <td width='28' style='font-size: 18px;'>⏰</td>
                          <td style='font-size: 15px; color: #334155;'><strong>Horario:</strong> <span style='color: #0f172a; font-weight: 800;'>$horarioTexto</span></td>
                        </tr>
                        <tr>
                          <td width='28' style='font-size: 18px;'>📍</td>
                          <td style='font-size: 15px; color: #334155;'><strong>Sede / Club:</strong> $clubNombre</td>
                        </tr>
                        <tr>
                          <td width='28' style='font-size: 18px;'>🎾</td>
                          <td style='font-size: 15px; color: #334155;'><strong>Cancha:</strong> <span style='color: #2563eb; font-weight: 800;'>$canchaNombre</span></td>
                        </tr>
                      </table>
                    </div>

                    <!-- RECORDATORIO IMPORTANTE -->
                    <div style='background-color: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; padding: 14px 18px; margin-bottom: 28px;'>
                      <p style='margin: 0; font-size: 13px; color: #92400e; line-height: 1.4;'>
                        ⏱️ <strong>Puntualidad:</strong> Se recomienda llegar con 10 minutos de anticipación para el calentamiento previo en cancha. El partido tiene una duración máxima asignada de 90 minutos.
                      </p>
                    </div>

                    <!-- BOTÓN CTA -->
                    <div style='text-align: center; margin-bottom: 12px;'>
                      <a href='https://www.padelblox.cl' style='display: inline-block; background-color: #0f172a; color: #ccff00; padding: 16px 36px; text-decoration: none; font-weight: 900; font-size: 15px; border-radius: 14px; box-shadow: 0 8px 20px rgba(0,0,0,0.15); text-transform: uppercase; letter-spacing: 0.5px;'>
                        VER FIXTURE Y TABLA EN LA APP
                      </a>
                    </div>
                  </div>

                  <!-- FOOTER -->
                  <div style='background-color: #f8fafc; padding: 20px; text-align: center; border-top: 1px solid #e2e8f0;'>
                    <p style='margin: 0; font-size: 12px; color: #94a3b8;'>
                      Este correo fue enviado automáticamente por el sistema de torneos y ligas de <strong>PadelBlox</strong>.<br>
                      &copy; " . date('Y') . " PadelBlox SpA. Todos los derechos reservados.
                    </p>
                  </div>

                </div>
              </td>
            </tr>
          </table>
        </body>
        </html>";

        // Despacho de Correos vía Resend API
        $emailsToSend = array_keys($emailTargets);
        if (!empty($emailsToSend)) {
            file_put_contents($debugLog, date('[Y-m-d H:i:s]') . " [LIGA NOTIF PARTIDO #$partido_id] Despachando correos a (" . count($emailsToSend) . "): " . implode(', ', $emailsToSend) . "\n", FILE_APPEND);
            enviarCorreoSMTP($emailsToSend, $subject, $bodyHTML);
        } else {
            file_put_contents($debugLog, date('[Y-m-d H:i:s]') . " [LIGA NOTIF PARTIDO #$partido_id] No se encontraron cuentas de correo para los jugadores.\n", FILE_APPEND);
        }

        // Despacho de Notificaciones Push a la App Móvil
        try {
            $helperPath = __DIR__ . '/../notifications/notificaciones_helper.php';
            if (file_exists($helperPath)) {
                require_once $helperPath;
                if (function_exists('notifyUser')) {
                    $pushTitle = ($tipo_evento === 'reprogramado') ? "⚠️ Partido de Liga Reprogramado" : "🎾 Partido de Liga Programado";
                    $pushMsg = "$pareja1_nombre vs $pareja2_nombre el $diaSemana $diaNum a las $horaInicio hrs en $canchaNombre ($clubNombre).";

                    foreach ($playerUserIds as $uId) {
                        notifyUser($conn, $uId, $pushTitle, $pushMsg, 'partido_liga');
                    }
                }
            }
        } catch (Throwable $ePush) {
            file_put_contents($debugLog, date('[Y-m-d H:i:s]') . " [LIGA PUSH ERROR] " . $ePush->getMessage() . "\n", FILE_APPEND);
        }

        return true;
    }
}

if (!function_exists('notificarMultiplesPartidosLiga')) {
    function notificarMultiplesPartidosLiga($conn, $partido_ids, $tipo_evento = 'programado') {
        if (empty($partido_ids) || !is_array($partido_ids)) return;
        foreach ($partido_ids as $pid) {
            notificarPartidoLiga($conn, (int)$pid, $tipo_evento);
        }
    }
}
?>
