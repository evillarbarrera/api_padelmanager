<?php
/**
 * Test & Diagnóstico del Sistema de Correo - Padelblox
 * Uso CLI: php test_mail.php [correo@destino.com]
 * Uso HTTP: https://api.padelmanager.cl/prd/system/test_mail.php?to=correo@destino.com
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/mail_config.php';
require_once __DIR__ . '/mail_service.php';

$destinatario = null;

if (php_sapi_name() === 'cli') {
    global $argv;
    $destinatario = $argv[1] ?? 'e.villarbarrera@gmail.com';
} else {
    $destinatario = $_GET['to'] ?? 'e.villarbarrera@gmail.com';
}

$destinatario = trim($destinatario);

if (!filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Dirección de correo no válida: ' . htmlspecialchars($destinatario)
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$fecha = date('Y-m-d H:i:s');
$asunto = "🏸 PADELBLOX - Prueba de Notificaciones [$fecha]";

$mensajeHTML = '
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #ffffff; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
    <div style="background-color: #0b132b; color: #ffffff; padding: 20px; text-align: center;">
        <h1 style="margin: 0; font-size: 24px; color: #ccff00; letter-spacing: 1px;">PADELBLOX</h1>
        <p style="margin: 5px 0 0 0; font-size: 14px; color: #e0e0e0;">Sistema de Gestión de Clubes y Reservas</p>
    </div>
    <div style="padding: 24px; color: #333333; line-height: 1.6;">
        <h2 style="color: #0b132b; margin-top: 0;">✅ Verificación de Correo Exitosa</h2>
        <p>Hola,</p>
        <p>Este es un correo de prueba generado automáticamente para verificar la conectividad y reputación del motor de mensajería de <strong>Padelblox</strong>.</p>
        
        <div style="background-color: #f7f9fc; border-left: 4px solid #ccff00; padding: 12px 16px; margin: 20px 0;">
            <p style="margin: 4px 0;"><strong>Destinatario:</strong> ' . htmlspecialchars($destinatario) . '</p>
            <p style="margin: 4px 0;"><strong>Fecha y Hora:</strong> ' . $fecha . '</p>
            <p style="margin: 4px 0;"><strong>Remitente Oficial:</strong> ' . MAIL_FROM_NAME . ' &lt;' . MAIL_FROM_EMAIL . '&gt;</p>
            <p style="margin: 4px 0;"><strong>Motor Principal:</strong> Resend API (DKIM / SPF Verificado)</p>
            <p style="margin: 4px 0;"><strong>Motor Fallback:</strong> PHPMailer SMTP (' . SMTP_HOST . ')</p>
        </div>
        
        <p>Si recibiste este correo en tu bandeja de entrada principal (y no en spam), la configuración de DNS, SPF y DKIM está operando correctamente.</p>
    </div>
    <div style="background-color: #f0f0f0; color: #777777; padding: 12px; text-align: center; font-size: 12px;">
        © ' . date('Y') . ' Padelblox / PadelManager. Todos los derechos reservados.
    </div>
</div>';

$resultado = enviarCorreoSMTP($destinatario, $asunto, $mensajeHTML);

// Obtener últimas líneas del log de debug
$logFile = __DIR__ . '/mail_debug.log';
$logTail = [];
if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $logTail = array_slice($lines, -10);
}

echo json_encode([
    'status' => $resultado['success'] ? 'success' : 'failed',
    'timestamp' => $fecha,
    'destinatario' => $destinatario,
    'resumen' => $resultado,
    'configuracion' => [
        'resend_configured' => defined('RESEND_API_KEY') && !empty(RESEND_API_KEY),
        'from_email' => defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : null,
        'from_name' => defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : null,
        'smtp_host' => defined('SMTP_HOST') ? SMTP_HOST : null,
        'smtp_user' => defined('SMTP_USER') ? SMTP_USER : null
    ],
    'ultimos_logs' => $logTail
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
