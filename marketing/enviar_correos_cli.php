<?php
/**
 * CLI de Envío Masivo de Email Marketing - Padelblox
 * Motor Híbrido: Resend API + PHPMailer SMTP
 */

require_once __DIR__ . '/../system/mail_config.php';
require_once __DIR__ . '/../system/mail_service.php';
require_once __DIR__ . '/enviar_promocion_padelblox.php';

$json_file = file_exists(__DIR__ . '/leads_email_consolidados.json') 
    ? __DIR__ . '/leads_email_consolidados.json' 
    : __DIR__ . '/leads_clubs_chile.json';

if (!file_exists($json_file)) {
    die("❌ Error: No se encontró el archivo de leads con email.\n");
}

$data = json_decode(file_get_contents($json_file), true);
$clubs = $data['clubs'] ?? [];

echo "\n======================================================================\n";
echo "🎾 PADELBLOX - SISTEMA DE ENVÍO POR CORREO ELECTRÓNICO\n";
echo "📧 Remitente: PADELBLOX <" . (defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : 'no_reply@padelmanager.cl') . ">\n";
echo "📨 Motor: Híbrido Resend API + PHPMailer SMTP (Ferozo)\n";
echo "📍 Total de clubes con correo en base de datos: " . count($clubs) . "\n";
echo "======================================================================\n\n";

$pendientes = array_filter($clubs, function($c) {
    return ($c['estado_envio'] ?? 'pendiente') === 'pendiente';
});

$enviados = array_filter($clubs, function($c) {
    return ($c['estado_envio'] ?? '') === 'enviado';
});

echo "📊 Estado actual:\n";
echo " - Pendientes: " . count($pendientes) . "\n";
echo " - Ya enviados: " . count($enviados) . "\n\n";

echo "Selecciona una opción:\n";
echo "1. 🧪 Enviar CORREO DE PRUEBA a Emmanuel Villar (e.villarbarrera@gmail.com)\n";
echo "2. 🚀 Enviar a TODOS los clubes PENDIENTES (" . count($pendientes) . " clubes)\n";
echo "3. 🔥 Enviar a TODOS los clubes (Pendientes + Ya enviados = " . count($clubs) . " clubes)\n";
echo "4. 📋 Ver listado de clubes y correos en pantalla\n";
echo "0. Salir\n\n";

$opc = trim(readline("Opción [1/2/3/4/0]: "));

if ($opc === '1') {
    echo "\n🧪 Enviando correo de prueba a e.villarbarrera@gmail.com...\n";
    $asunto = "🎾 [3 Meses Gratis] Software Integral para Clubes de Pádel: Torneos, Caja POS y Smartwatch en Cancha";
    $html = generar_html_promocion("Club de Prueba Padelblox", "e.villarbarrera@gmail.com");
    $res = enviarCorreoSMTP("e.villarbarrera@gmail.com", $asunto, $html);
    
    if (!empty($res['success'])) {
        echo "✅ ¡Correo de prueba enviado con éxito! Revisa tu bandeja de entrada.\n";
    } else {
        echo "❌ Error al enviar prueba: " . ($res['error'] ?? 'Desconocido') . "\n";
    }
} elseif ($opc === '2' || $opc === '3') {
    $lista_envio = ($opc === '2') ? array_values($pendientes) : $clubs;
    
    if (empty($lista_envio)) {
        echo "⚠️ No hay clubes para enviar con el criterio seleccionado.\n";
        exit;
    }
    
    echo "\n⚠️ ATENCIÓN: Se enviarán correos a " . count($lista_envio) . " clubes.\n";
    $confirma = trim(readline("¿Deseas continuar? (escribe 'SI' para confirmar): "));
    
    if (strtoupper($confirma) !== 'SI') {
        echo "Operación cancelada.\n";
        exit;
    }
    
    echo "\n🚀 Iniciando despacho de correos...\n";
    $exitos = 0;
    $fallidos = 0;
    
    foreach ($lista_envio as $idx => $club) {
        $nombre = $club['nombre_club'];
        $email = $club['email'];
        $ciudad = $club['ciudad'] ?? 'su zona';
        
        $num = $idx + 1;
        echo "[$num/" . count($lista_envio) . "] Despachando a: $nombre <$email> ($ciudad)... ";
        
        $asunto = "🎾 [3 Meses Gratis] Software Integral para Clubes de Pádel: Torneos, Caja POS y Smartwatch en Cancha";
        $html = generar_html_promocion($nombre, $email);
        $res = enviarCorreoSMTP($email, $asunto, $html);
        
        if (!empty($res['success'])) {
            echo "✅ ENVIADO\n";
            $exitos++;
            
            // Actualizar estado en el array principal
            foreach ($data['clubs'] as &$orig) {
                if ($orig['id'] == $club['id'] || $orig['email'] == $email) {
                    $orig['estado_envio'] = 'enviado';
                    $orig['fecha_envio_correo'] = date('Y-m-d H:i:s');
                }
            }
        } else {
            echo "❌ FALLÓ (" . ($res['error'] ?? 'Error') . ")\n";
            $fallidos++;
        }
        
        // Guardar progreso cada 5 envíos
        if ($num % 5 === 0) {
            file_put_contents($json_file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        
        // Pausa de 1.5s entre envíos para entregabilidad y evitar rate-limits
        sleep(1);
    }
    
    file_put_contents($json_file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "\n🎉 Proceso completado. Exitosos: $exitos | Fallidos: $fallidos\n";
    echo "💾 Archivo $json_file actualizado con las fechas de envío.\n";

} elseif ($opc === '4') {
    echo "\n" . str_repeat("=", 85) . "\n";
    printf("%-4s %-32s %-32s %-12s\n", "#", "Club", "Email", "Estado");
    echo str_repeat("=", 85) . "\n";
    foreach ($clubs as $c) {
        printf("%-4s %-32s %-32s %-12s\n", 
            $c['id'], 
            substr($c['nombre_club'], 0, 30), 
            substr($c['email'], 0, 30), 
            $c['estado_envio'] ?? 'pendiente'
        );
    }
    echo str_repeat("=", 85) . "\n";
} else {
    echo "Operación finalizada.\n";
}
