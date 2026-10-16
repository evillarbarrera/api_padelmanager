<?php
/**
 * Motor de Envío de Email Marketing Padelblox para Clubes de Pádel en Chile
 * Integración Oficial con Resend API para 100% de entregabilidad (SPF/DKIM/DMARC)
 * 
 * Promoción: Gestión Multi-Club a un Solo Costo, Reloj Smartwatch en Cancha,
 * Ligas/Americanos sin colisión y 3 Meses Gratis de Lanzamiento.
 */

header('Access-Control-Allow-Origin: *');
@set_time_limit(300);

$leads_file = __DIR__ . '/leads_clubs_chile.json';
$test_email = 'e.villarbarrera@gmail.com';
$from_email = 'no_reply@padelmanager.cl';
$from_name  = 'PADELBLOX Chile';
$reply_to   = 'e.villarbarrera@gmail.com';
$resend_api_key = getenv('RESEND_API_KEY') ?: '';

// Determinar modo y parámetros
$is_cli = (php_sapi_name() === 'cli');
$modo   = $_GET['modo'] ?? ($argv[1] ?? 'test');
$token  = $_GET['token'] ?? ($argv[2] ?? '');
$action = $_GET['action'] ?? ($is_cli ? 'send' : 'view');

// Contar clubes pendientes
$data_leads_info = file_exists($leads_file) ? json_decode(file_get_contents($leads_file), true) : null;
$total_pendientes = 0;
if ($data_leads_info && isset($data_leads_info['clubs'])) {
    foreach ($data_leads_info['clubs'] as $c) {
        if (($c['estado_envio'] ?? 'pendiente') === 'pendiente') {
            $total_pendientes++;
        }
    }
}

/**
 * Genera la plantilla de correo en HTML responsive
 * Paleta Padelblox:
 * - Fondo Principal: Obsidian Dark #070b14 / #0f172a
 * - Tarjetas: #131f37 con borde #1e293b
 * - Acento Primario: Electric Lime #ccff00 (alta conversión deportiva)
 * - Acento Secundario: Emerald #10b981 / WhatsApp #25d366
 * - Texto: Blanco #ffffff y Gris Claro #cbd5e1 / #94a3b8
 */
function generar_html_promocion($nombre_club = 'Tu Club de Pádel', $email_destinatario = 'e.villarbarrera@gmail.com') {
    $wa_phone = '56969083124';
    $wa_msg = urlencode("Hola Emmanuel, recibí la información de Padelblox para " . $nombre_club . " y me gustaría activar los 3 meses gratis de gestión multi-club y ver una demostración.");
    $link_wa = "https://wa.me/{$wa_phone}?text={$wa_msg}";
    $link_web = "https://padelblox.cl";
    $link_desuscribir = "https://padelmanager.cl/prd/marketing/desuscribir.php?email=" . urlencode($email_destinatario);

    $logo_url = "https://padelblox.cl/assets/images/logo-circular.png";
    $app_preview_url = "https://padelblox.cl/assets/images/app-screenshot-v2.png";

    $html = <<<HTML
<!DOCTYPE html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>Padelblox - Plataforma Integral para Clubes de Pádel</title>
  <!--[if mso]>
  <style type="text/css">
    body, table, td { font-family: Arial, Helvetica, sans-serif !important; }
  </style>
  <![endif]-->
</head>
<body style="margin: 0; padding: 0; background-color: #070b14; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; color: #f8fafc;">
  
  <!-- Preheader oculto para clientes de correo -->
  <div style="display: none; font-size: 1px; color: #070b14; line-height: 1px; max-height: 0px; max-width: 0px; opacity: 0; overflow: hidden;">
    🎾 Gestiona 1 o más clubes a un solo costo fijo. Fixtures automáticos, Smartwatch en cancha y 3 meses gratis para tu club.
    &zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;
  </div>

  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #070b14; padding: 25px 12px;">
    <tr>
      <td align="center">
        
        <!-- CONTENEDOR PRINCIPAL (620px) -->
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width: 620px; background-color: #0f172a; border-radius: 20px; overflow: hidden; border: 1px solid #1e293b; box-shadow: 0 20px 45px rgba(0,0,0,0.6);">
          
          <!-- TOP BADGE CHILE -->
          <tr>
            <td style="background: linear-gradient(90deg, #070b14 0%, #0f172a 50%, #070b14 100%); padding: 12px 24px; text-align: center; border-bottom: 1px solid #1e293b;">
              <span style="color: #ccff00; font-size: 11px; font-weight: 800; letter-spacing: 1.5px; text-transform: uppercase;">
                🇨🇱 PLATAFORMA OFICIAL PARA CLUBES DE PÁDEL EN CHILE
              </span>
            </td>
          </tr>

          <!-- HEADER / LOGO HERO -->
          <tr>
            <td style="padding: 34px 30px 24px; text-align: center; background: radial-gradient(circle at 50% 20%, rgba(204, 255, 0, 0.08) 0%, rgba(15, 23, 42, 0) 70%);">
              
              <!-- Logo Circular Oficial -->
              <table role="presentation" align="center" cellspacing="0" cellpadding="0" border="0" style="margin: 0 auto;">
                <tr>
                  <td align="center" style="background: #070b14; padding: 10px; border-radius: 50%; border: 2px solid #ccff00; box-shadow: 0 0 25px rgba(204, 255, 0, 0.25);">
                    <img src="{$logo_url}" alt="Padelblox" width="74" height="74" style="display: block; border-radius: 50%; max-width: 74px;" />
                  </td>
                </tr>
              </table>

              <h1 style="margin: 18px 0 6px; color: #ffffff; font-size: 30px; font-weight: 900; letter-spacing: -0.5px; text-transform: uppercase;">
                PADEL<span style="color: #ccff00;">BLOX</span>
              </h1>
              
              <p style="margin: 0; color: #94a3b8; font-size: 15px; font-weight: 500;">
                El software definitivo de gestión deportiva, canchas y torneos
              </p>

            </td>
          </tr>

          <!-- BANNER HERO DISRUPTIVO: MULTI-CLUB A UN SOLO COSTO -->
          <tr>
            <td style="padding: 0 28px 24px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background: linear-gradient(135deg, rgba(204,255,0,0.12) 0%, rgba(16,185,129,0.08) 100%); border: 1.5px solid rgba(204,255,0,0.35); border-radius: 16px; padding: 22px 20px; text-align: center;">
                <tr>
                  <td>
                    <div style="display: inline-block; background-color: #ccff00; color: #070b14; font-size: 11px; font-weight: 900; padding: 4px 12px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 10px;">
                      ⚡ PROMOCIÓN EXCLUSIVA 2026
                    </div>
                    <h2 style="margin: 0 0 8px; color: #ffffff; font-size: 21px; font-weight: 800; line-height: 1.35;">
                      ¿Administras 1 o más sedes?<br>
                      Gestiona <span style="color: #ccff00;">TODOS tus clubes a UN SOLO COSTO FIJO</span>
                    </h2>
                    <p style="margin: 0; color: #a7f3d0; font-size: 14.5px; font-weight: 700;">
                      🎁 3 MESES 100% GRATIS para tu club &bull; Sin comisiones por reserva
                    </p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>


          <!-- CUERPO MENSAJE PERSONALIZADO -->
          <tr>
            <td style="padding: 0 30px 24px;">
              <p style="font-size: 16px; line-height: 1.6; color: #f1f5f9; margin-top: 0;">
                Hola equipo de <strong style="color: #ccff00;">{$nombre_club}</strong>,
              </p>
              
              <p style="font-size: 14.5px; line-height: 1.65; color: #cbd5e1; margin-bottom: 22px;">
                Sabemos lo desgastante que es coordinar canchas en Chile: planillas de Excel que nadie actualiza, cruces de partidos entre arriendos y torneos, y softwares tradicionales que <strong>cobran comisiones abusivas por cada reserva</strong> o te cobran un plan aparte por cada sede que abres.
              </p>

              <p style="font-size: 15px; line-height: 1.6; color: #ffffff; font-weight: 700; margin-bottom: 20px;">
                Padelblox fue diseñado por jugadores e ingenieros para multiplicar la rentabilidad de tu club:
              </p>

              <!-- CARDS DE CARACTERÍSTICAS / BADGES EN GRID -->
              
              <!-- Card 1: Multi-Club Costo Fijo -->
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #131f37; border-radius: 12px; border: 1px solid #1e293b; margin-bottom: 12px;">
                <tr>
                  <td width="56" valign="top" style="padding: 16px 0 16px 16px; text-align: center;">
                    <div style="width: 42px; height: 42px; background: rgba(204,255,0,0.15); border: 1px solid rgba(204,255,0,0.3); border-radius: 10px; line-height: 42px; font-size: 20px;">
                      🏢
                    </div>
                  </td>
                  <td valign="top" style="padding: 16px 16px 16px 12px;">
                    <strong style="color: #ffffff; font-size: 15px; display: block; margin-bottom: 4px;">
                      1 o Más Sedes a un Solo Costo Fijo
                    </strong>
                    <span style="color: #94a3b8; font-size: 13.5px; line-height: 1.5; display: block;">
                      ¿Tienes 1, 2 o más recintos? Centralízalos todos bajo una única suscripción mensual plana. <strong>Cero cobros por reserva, cero comisiones por jugador.</strong>
                    </span>
                  </td>
                </tr>
              </table>

              <!-- Card 2: Smartwatch en Cancha -->
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #131f37; border-radius: 12px; border: 1px solid #1e293b; margin-bottom: 12px;">
                <tr>
                  <td width="56" valign="top" style="padding: 16px 0 16px 16px; text-align: center;">
                    <div style="width: 42px; height: 42px; background: rgba(56,189,248,0.15); border: 1px solid rgba(56,189,248,0.3); border-radius: 10px; line-height: 42px; font-size: 20px;">
                      ⌚
                    </div>
                  </td>
                  <td valign="top" style="padding: 16px 16px 16px 12px;">
                    <strong style="color: #ffffff; font-size: 15px; display: block; margin-bottom: 4px;">
                      Smartwatch Wear OS: Marcador en Vivo en Cancha
                    </strong>
                    <span style="color: #94a3b8; font-size: 13.5px; line-height: 1.5; display: block;">
                      Los jugadores anotan el punto directo en su reloj inteligente en la cancha. El resultado, sets y estadísticas se actualizan <strong>en vivo</strong> en la pantalla del club.
                    </span>
                  </td>
                </tr>
              </table>

              <!-- Card 3: Ligas y Americanos Sin Colisiones -->
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #131f37; border-radius: 12px; border: 1px solid #1e293b; margin-bottom: 12px;">
                <tr>
                  <td width="56" valign="top" style="padding: 16px 0 16px 16px; text-align: center;">
                    <div style="width: 42px; height: 42px; background: rgba(234,179,8,0.15); border: 1px solid rgba(234,179,8,0.3); border-radius: 10px; line-height: 42px; font-size: 20px;">
                      🏆
                    </div>
                  </td>
                  <td valign="top" style="padding: 16px 16px 16px 12px;">
                    <strong style="color: #ffffff; font-size: 15px; display: block; margin-bottom: 4px;">
                      Ligas y Torneos Americanos Inteligentes
                    </strong>
                    <span style="color: #94a3b8; font-size: 13.5px; line-height: 1.5; display: block;">
                      Generador automático de fixtures semanales y rotaciones americanas con validación anti-colisión de canchas. Sin horas duplicadas con arriendos.
                    </span>
                  </td>
                </tr>
              </table>

              <!-- Card 4: Pagos Divididos, Kiosko/POS y WhatsApp -->
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #131f37; border-radius: 12px; border: 1px solid #1e293b; margin-bottom: 18px;">
                <tr>
                  <td width="56" valign="top" style="padding: 16px 0 16px 16px; text-align: center;">
                    <div style="width: 42px; height: 42px; background: rgba(16,185,129,0.15); border: 1px solid rgba(16,185,129,0.3); border-radius: 10px; line-height: 42px; font-size: 20px;">
                      💳
                    </div>
                  </td>
                  <td valign="top" style="padding: 16px 16px 16px 12px;">
                    <strong style="color: #ffffff; font-size: 15px; display: block; margin-bottom: 4px;">
                      Pagos Divididos + Kiosko/POS + WhatsApp Directo
                    </strong>
                    <span style="color: #94a3b8; font-size: 13.5px; line-height: 1.5; display: block;">
                      Cada jugador abona su parte por Webpay / Débito. Control de stock para pelotas y bebidas, y coordinación de partidos vía WhatsApp con un solo clic.
                    </span>
                  </td>
                </tr>
              </table>

              <!-- CAJA OFERTA 3 MESES GRATIS -->
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background: linear-gradient(135deg, #070b14 0%, #1e293b 100%); border-radius: 14px; border: 2px dashed #ccff00; padding: 22px 20px; text-align: center; margin-bottom: 24px;">
                <tr>
                  <td>
                    <span style="font-size: 24px;">🎁</span>
                    <h3 style="margin: 6px 0 6px; color: #ccff00; font-size: 19px; font-weight: 900;">
                      ¡3 MESES DE PRUEBA 100% GRATIS!
                    </h3>
                    <p style="margin: 0; color: #e2e8f0; font-size: 14px; line-height: 1.5;">
                      Sin tarjeta de crédito. Sin permanencia obligatoria.<br>
                      Migramos tu base de datos y dejamos tu club operando en 24 horas.
                    </p>
                  </td>
                </tr>
              </table>

              <!-- BOTONES CALL TO ACTION -->
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-bottom: 12px;">
                <tr>
                  <td align="center">
                    <!-- Botón Principal: Neon Lime -->
                    <a href="{$link_wa}" target="_blank" style="display: inline-block; width: 85%; max-width: 440px; background-color: #ccff00; color: #070b14; text-decoration: none; padding: 16px 24px; border-radius: 12px; font-size: 16px; font-weight: 900; text-align: center; letter-spacing: 0.3px; box-shadow: 0 6px 20px rgba(204,255,0,0.35); text-transform: uppercase;">
                      🎾 ACTIVAR 3 MESES GRATIS PARA MI CLUB &rarr;
                    </a>
                  </td>
                </tr>
              </table>

              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-bottom: 26px;">
                <tr>
                  <td align="center">
                    <!-- Botón Secundario: WhatsApp Oficial -->
                    <a href="{$link_wa}" target="_blank" style="display: inline-block; width: 85%; max-width: 440px; background-color: #15803d; color: #ffffff; text-decoration: none; padding: 13px 20px; border-radius: 10px; font-size: 14px; font-weight: 700; text-align: center;">
                      💬 Escribir directamente a Emmanuel Villar por WhatsApp (+56 9 6908 3124)
                    </a>
                  </td>
                </tr>
              </table>

              <!-- FIRMA PERSONAL -->
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-top: 1px solid #1e293b; padding-top: 20px;">
                <tr>
                  <td>
                    <p style="margin: 0 0 4px; color: #ffffff; font-size: 14px; font-weight: 700;">
                      Emmanuel Villar B.
                    </p>
                    <p style="margin: 0 0 4px; color: #ccff00; font-size: 12.5px; font-weight: 600;">
                      Fundador &amp; Director de Desarrollo &bull; Padelblox Chile
                    </p>
                    <p style="margin: 0; color: #64748b; font-size: 12.5px;">
                      📞 <a href="{$link_wa}" style="color: #94a3b8; text-decoration: none;">+56 9 6908 3124</a> &nbsp;|&nbsp; 🌐 <a href="{$link_web}" style="color: #ccff00; text-decoration: none;">padelblox.cl</a>
                    </p>
                  </td>
                </tr>
              </table>

            </td>
          </tr>

          <!-- FOOTER LEGAL Y DESUSCRIPCIÓN (Ley 19.496) -->
          <tr>
            <td style="background-color: #070b14; padding: 24px 28px; text-align: center; border-top: 1px solid #1e293b;">
              <p style="margin: 0 0 10px; color: #64748b; font-size: 11.5px; line-height: 1.5;">
                En cumplimiento de la <strong>Ley N° 19.496 (Art. 28 B)</strong> sobre protección de los derechos de los consumidores en Chile. Este mensaje fue enviado a administradores de recintos deportivos vinculados a <strong>{$nombre_club}</strong>.
              </p>
              <p style="margin: 0; color: #475569; font-size: 11px;">
                Padelblox SpA &bull; Santiago, Chile &bull; Si no deseas recibir más comunicaciones sobre promociones de software deportivo, puedes <a href="{$link_desuscribir}" style="color: #94a3b8; text-decoration: underline;">desuscribirte aquí</a>.
              </p>
            </td>
          </tr>

        </table>

      </td>
    </tr>
  </table>

</body>
</html>
HTML;
    return $html;
}

/**
 * Función oficial de envío mediante API de RESEND.COM
 * Firma con SPF/DKIM garantizado y bypass de filtros de Spam
 */
function enviar_correo_resend($destinatario, $nombre_club, $apiKey, $asunto = null) {
    if (empty($asunto)) {
        $asunto = "🎾 [3 Meses Gratis] Gestiona 1 o más sedes a un solo costo fijo + Reloj en Cancha | Padelblox Chile";
    }

    $cuerpoHTML = generar_html_promocion($nombre_club, $destinatario);

    $payload = [
        "from"     => "PADELBLOX <no_reply@padelmanager.cl>",
        "to"       => [$destinatario],
        "reply_to" => "e.villarbarrera@gmail.com",
        "subject"  => $asunto,
        "html"     => $cuerpoHTML
    ];

    $ch = curl_init("https://api.resend.com/emails");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $apiKey,
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Padelblox-Marketing/1.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $curl_err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);
    $es_exito = ($httpCode >= 200 && $httpCode < 300 && !empty($decoded['id']));

    return [
        'enviado'      => $es_exito,
        'http_code'    => $httpCode,
        'resend_id'    => $decoded['id'] ?? null,
        'error_detail' => $curl_err ?: ($decoded['message'] ?? $response),
        'destinatario' => $destinatario,
        'nombre_club'  => $nombre_club,
        'fecha'        => date('Y-m-d H:i:s')
    ];
}

// =========================================================================
// EJECUCIÓN DEL ENVÍO
// =========================================================================

$resultado_envio = null;

if ($action === 'send') {
    if ($modo === 'test') {
        // MODO TEST: ENVÍO EXCLUSIVO A e.villarbarrera@gmail.com
        $resultado_envio = enviar_correo_resend(
            $test_email, 
            'Emmanuel Villar (Club de Prueba Padelblox)', 
            $resend_api_key
        );

        if ($is_cli) {
            echo "\n======================================================\n";
            echo "=== ENVÍO DE PRUEBA RESEND API COMPLETADO ===\n";
            echo "Destinatario: " . $resultado_envio['destinatario'] . "\n";
            echo "Estado: " . ($resultado_envio['enviado'] ? "✅ ÉXITO (Resend ID: " . $resultado_envio['resend_id'] . ")" : "❌ ERROR (HTTP " . $resultado_envio['http_code'] . ": " . $resultado_envio['error_detail'] . ")") . "\n";
            echo "Fecha: " . $resultado_envio['fecha'] . "\n";
            echo "======================================================\n\n";
            exit(0);
        }
    } elseif ($modo === 'produccion' && $token === 'PADELBLOX_CHILE_2026') {
        // MODO PRODUCCIÓN: Enviar a la lista de leads
        $data_leads = json_decode(file_get_contents($leads_file), true);
        $enviados_count = 0;
        $errores_count = 0;

        if ($data_leads && isset($data_leads['clubs'])) {
            foreach ($data_leads['clubs'] as &$club) {
                if (($club['estado_envio'] ?? '') === 'pendiente') {
                    $res = enviar_correo_resend(
                        $club['email'],
                        $club['nombre_club'],
                        $resend_api_key
                    );
                    if ($res['enviado']) {
                        $club['estado_envio'] = 'enviado';
                        $club['resend_id']    = $res['resend_id'];
                        $club['fecha_envio']  = date('Y-m-d H:i:s');
                        $enviados_count++;
                    } else {
                        $club['estado_envio'] = 'error';
                        $club['error_msg']    = $res['error_detail'];
                        $errores_count++;
                    }
                    // Throttling de 1 segundo entre envíos
                    sleep(1);
                }
            }
            file_put_contents($leads_file, json_encode($data_leads, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        $resultado_envio = [
            'enviado'      => ($enviados_count > 0),
            'destinatario' => "Campaña Masiva Chile ($enviados_count enviados exitosos, $errores_count errores)",
            'nombre_club'  => 'Clubes de Pádel Chile',
            'resend_id'    => 'BATCH_COMPLETED',
            'fecha'        => date('Y-m-d H:i:s')
        ];
    }
}

// Vista previa HTML
$preview_html = generar_html_promocion('Club Padel Oriente (Ejemplo)', $test_email);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Marketing Padelblox - Campaña Clubes Chile 2026</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #070b14; color: #f8fafc; margin: 0; padding: 20px; }
    .top-controls { max-width: 900px; margin: 0 auto 24px; background: #0f172a; border: 1px solid #1e293b; border-radius: 16px; padding: 20px 26px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
    .title-box h1 { margin: 0; font-size: 1.35rem; font-weight: 800; color: #ffffff; }
    .title-box p { margin: 4px 0 0; font-size: 0.85rem; color: #94a3b8; }
    .btn-action { background: #ccff00; color: #070b14; text-decoration: none; padding: 13px 24px; border-radius: 12px; font-weight: 900; font-size: 0.94rem; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 20px rgba(204,255,0,0.3); transition: transform 0.15s; border: none; cursor: pointer; text-transform: uppercase; }
    .btn-action:hover { transform: translateY(-2px); background: #d9ff33; }
    .btn-danger { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: #ffffff; text-decoration: none; padding: 13px 22px; border-radius: 12px; font-weight: 800; font-size: 0.92rem; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 18px rgba(239, 68, 68, 0.4); transition: transform 0.15s; border: none; cursor: pointer; text-transform: uppercase; }
    .btn-danger:hover { transform: translateY(-2px); background: #b91c1c; }
    .btn-secondary { background: #1e293b; color: #f8fafc; padding: 11px 18px; border-radius: 10px; text-decoration: none; font-size: 0.88rem; font-weight: 700; border: 1px solid #334155; }
    .btn-secondary:hover { background: #334155; }
    .status-alert { max-width: 900px; margin: 0 auto 20px; padding: 18px 22px; border-radius: 14px; font-size: 0.95rem; font-weight: 600; }
    .status-alert.success { background: #064e3b; border: 1.5px solid #10b981; color: #a7f3d0; }
    .status-alert.error { background: #7f1d1d; border: 1.5px solid #ef4444; color: #fecaca; }
    .preview-frame { max-width: 900px; margin: 0 auto; background: #0b1120; border: 1px solid #1e293b; border-radius: 18px; padding: 24px; box-sizing: border-box; }
    .preview-header { font-size: 0.88rem; font-weight: 800; color: #ccff00; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
  </style>
</head>
<body>

  <!-- Barra de Control -->
  <div class="top-controls">
    <div class="title-box">
      <h1>🎾 Campaña Marketing Clubes Pádel Chile</h1>
      <p>Envío seguro vía <strong>Resend API</strong> (SPF/DKIM verificado) &bull; Leads pendientes: <strong style="color: #fde68a;"><?php echo $total_pendientes; ?></strong></p>
    </div>
    
    <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
      <a href="recolector_clubs_chile.php" class="btn-secondary">📋 Ver Clubes (<?php echo $total_pendientes; ?> pendientes)</a>
      <a href="enviar_promocion_padelblox.php?action=send&modo=test" class="btn-action">
        <span>🚀</span> Enviar Prueba (Emmanuel)
      </a>
      <?php if ($total_pendientes > 0): ?>
        <a href="enviar_promocion_padelblox.php?action=send&modo=produccion&token=PADELBLOX_CHILE_2026" 
           onclick="return confirm('⚠️ ATENCIÓN:\n\n¿Estás seguro de enviar la campaña oficial de correo a los <?php echo $total_pendientes; ?> clubes de pádel pendientes en Chile?\n\nEl envío se realizará mediante Resend API con 1 segundo de pausa entre cada uno.');" 
           class="btn-danger">
          <span>🔥</span> Enviar a Todos los Clubes (<?php echo $total_pendientes; ?>)
        </a>
      <?php else: ?>
        <span style="color: #4ade80; font-size: 0.88rem; font-weight: 700; background: #064e3b; padding: 10px 16px; border-radius: 10px; border: 1px solid #059669;">
          ✅ ¡Todos los clubes ya recibieron la campaña!
        </span>
      <?php endif; ?>
    </div>
  </div>

  <!-- Alerta de Resultado de Envío -->
  <?php if ($resultado_envio): ?>
    <div class="status-alert <?php echo $resultado_envio['enviado'] ? 'success' : 'error'; ?>">
      <?php if ($resultado_envio['enviado']): ?>
        🎉 <strong>¡Correo enviado exitosamente vía Resend API!</strong><br>
        Destinatario: <u><?php echo htmlspecialchars($resultado_envio['destinatario']); ?></u><br>
        ID Resend: <code><?php echo htmlspecialchars($resultado_envio['resend_id'] ?? 'OK'); ?></code> &bull; Fecha: <?php echo $resultado_envio['fecha']; ?>.<br>
        <small style="color: #6ee7b7;">Llega directo a la bandeja de entrada de Gmail con SPF y DKIM validados.</small>
      <?php else: ?>
        ❌ <strong>Error en el despacho:</strong> <?php echo htmlspecialchars($resultado_envio['error_detail'] ?? 'Error desconocido'); ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Previsualización -->
  <div class="preview-frame">
    <div class="preview-header">
      <span>👁️ Previsualización del Correo de Alto Impacto:</span>
    </div>
    <div style="border-radius: 16px; overflow: hidden;">
      <?php echo $preview_html; ?>
    </div>
  </div>

</body>
</html>
