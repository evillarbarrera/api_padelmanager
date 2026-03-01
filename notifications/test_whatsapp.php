<?php
require_once 'whatsapp_service.php';

// ATENCIÓN:
// Cambia este número por el TUYO (el que verificaste en Meta), incluyendo código de país (569...)
// Si no pones tu número, dará error porque en modo prueba no puedes enviar a desconocidos.
$miCelular = '56934516352'; // <--- PON TU NUMERO REAL AQUÍ PARA PROBAR (ej: 569...)

// Usamos la plantilla "hello_world" que Meta te da por defecto al crear la cuenta.
// No requiere variables, así que el array va vacío.
$respuesta = enviarWhatsApp($miCelular, 'hello_world', 'en_US');

echo "<pre>";
print_r($respuesta);
echo "</pre>";

if ($respuesta['success']) {
    echo "<h1>¡Mensaje enviado correctamente! Revisá tu WhatsApp.</h1>";
} else {
    echo "<h1>Error al enviar. Revisa el Token/ID o si el número destino está verificado.</h1>";
}
?>
