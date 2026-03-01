<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$to = "ejvillarb@gmail.com"; // Reemplaza con tu correo para probar
$subject = "Prueba de Correos - Padel Manager";
$message = "Si recibes este correo, la funcion mail() de PHP esta funcionando correctamente en tu servidor.";
$headers = "From: Padel Manager <no-reply@padelmanager.cl>\r\n";
$headers .= "Reply-To: soporte@padelmanager.cl\r\n";
$headers .= "X-Mailer: PHP/" . phpversion();

echo "<h1>Probando envio de correo...</h1>";
echo "Destinatario: $to <br>";

if (mail($to, $subject, $message, $headers)) {
    echo "<h2 style='color: green;'>✅ El servidor REPORTO que el correo fue enviado con exito.</h2>";
    echo "<p>Por favor revisa tu bandeja de entrada y la carpeta de SPAM.</p>";
} else {
    echo "<h2 style='color: red;'>❌ El servidor FALLO al intentar enviar el correo.</h2>";
    $last_error = error_get_last();
    if ($last_error) {
        echo "Detalle del error: " . $last_error['message'];
    } else {
        echo "No hay detalles adicionales. Esto suele significar que la funcion mail() esta deshabilitada en el php.ini del servidor.";
    }
}
?>
