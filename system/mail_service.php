<?php
/**
 * Servicio de envío de correos vía SMTP usando cURL
 */

function enviarCorreoSMTP($to, $subject, $bodyHTML) {
    $host = 'c2632100.ferozo.com';
    $port = 465;
    $username = 'no_reply@padelmanager.cl';
    $password = 'H@kgrp6B';
    $fromName = 'Padel Manager';

    // Construir el mensaje raw con formato MIME
    $boundary = md5(uniqid(time()));
    
    $headers = [
        "From: $fromName <$username>",
        "To: $to",
        "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8",
        "Content-Transfer-Encoding: 8bit"
    ];

    $messageBody = implode("\r\n", $headers) . "\r\n\r\n" . $bodyHTML;

    $ch = curl_init();

    // Configuración para SMTP sobre SSL (puerto 465)
    curl_setopt($ch, CURLOPT_URL, "smtps://$host:$port");
    curl_setopt($ch, CURLOPT_MAIL_FROM, "<$username>");
    curl_setopt($ch, CURLOPT_MAIL_RCPT, ["<$to>"]);
    
    // Autenticación
    curl_setopt($ch, CURLOPT_USERNAME, $username);
    curl_setopt($ch, CURLOPT_PASSWORD, $password);
    
    // Payload del mensaje
    curl_setopt($ch, CURLOPT_UPLOAD, true);
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $messageBody);
    rewind($stream);
    curl_setopt($ch, CURLOPT_READFUNCTION, function($ch, $fd, $length) use ($stream) {
        return fread($stream, $length);
    });

    // Seguridad y detalles
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USE_SSL, CURLUSESSL_ALL);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_VERBOSE, false);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    fclose($stream);

    if ($response) {
        return ['success' => true];
    } else {
        return ['success' => false, 'error' => $error];
    }
}
?>
