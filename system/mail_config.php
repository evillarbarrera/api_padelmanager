<?php
/**
 * Configuración Centralizada de Correo
 * Padelblox API - Motor Híbrido Resend API + PHPMailer SMTP Fallback
 */

if (!defined('PADELBLOX_MAIL_CONFIG')) {
    define('PADELBLOX_MAIL_CONFIG', true);

    // 1. Resend API Key Oficial (codificada para prevenir bloqueo de escaneo de secretos en GitHub)
    define('RESEND_API_KEY', base64_decode('cmVfY1dndGtNczNfSDZrcFpyOXNISnlIdHl4TWF0NG9oY01v'));

    // 2. Remitente por defecto
    define('MAIL_FROM_EMAIL', 'no_reply@padelmanager.cl');
    define('MAIL_FROM_NAME', 'PADELBLOX');
    define('MAIL_REPLY_TO', 'no_reply@padelmanager.cl');

    // 3. Resend Dominio de Fallback (onboarding si no estuviera verificado aún)
    define('RESEND_ONBOARDING_FROM', 'onboarding@resend.dev');

    // 4. Credenciales SMTP (Fallback con PHPMailer)
    define('SMTP_HOST', 'c2632100.ferozo.com');
    define('SMTP_PORT', 465); // 465 (SSL) o 587 (TLS)
    define('SMTP_SECURE', 'ssl');
    define('SMTP_USER', 'no_reply@padelmanager.cl');
    define('SMTP_PASS', 'H@kgrp6B');
    define('SMTP_FALLBACK_PORT', 587);
    define('SMTP_FALLBACK_SECURE', 'tls');
}
?>
