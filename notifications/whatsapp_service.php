<?php
/**
 * Servicio para enviar mensajes vía WhatsApp Cloud API
 */

function enviarWhatsApp($destinatario, $templateNombre, $idioma = 'es', $variables = []) {
    // ---------------- CONFIGURACIÓN ----------------
    // Token de Acceso (Meta Developers)
    $token = 'EAARqYVtkO0UBQnjcmCcLywM1vcxrtr2j235ZA5NH24sBye15WUaMV0E4oHS1RsQiNBXtTW09ajcHnLaV8kC7lFvYEAhoZAkigSCk83ZCiM2Sc4OAiRxICkpoIR0K24x0i0gLVOStNXRe94Pz6V57m07ILN9jjuUkfNtOBKmdQposjU35ZAs3yO8xLKeTlzU8ewZDZD'; 
    $phoneId = '931093263427318'; 
    $version = 'v17.0'; 
    // -----------------------------------------------

    // LIMPIEZA DE TELÉFONO: Solo números
    $destinatario = preg_replace('/[^0-9]/', '', $destinatario);

    // FIX PARA CHILE: Si tiene 9 dígitos (ej: 9XXXXYYYY), anteponer 56
    if (strlen($destinatario) === 9 && $destinatario[0] === '9') {
        $destinatario = '56' . $destinatario;
    }

    $url = "https://graph.facebook.com/{$version}/{$phoneId}/messages";

    $data = [
        'messaging_product' => 'whatsapp',
        'to' => $destinatario,
        'type' => 'template',
        'template' => [
            'name' => $templateNombre,
            'language' => [
                'code' => $idioma
            ]
        ]
    ];

    if (!empty($variables)) {
        $parameters = [];
        foreach ($variables as $var) {
            $parameters[] = [
                'type' => 'text',
                'text' => strval($var)
            ];
        }

        $data['template']['components'] = [
            [
                'type' => 'body',
                'parameters' => $parameters
            ]
        ];
    }

    $jsonPayload = json_encode($data);
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $decoded = json_decode($response, true);

    // LOGGING
    $logFile = __DIR__ . '/whatsapp_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] TO: $destinatario | TMPL: $templateNombre | LANG: $idioma | CODE: $httpCode | RESP: $response\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);

    // AUTO-RETRY with 'es' if 'es_CL' fails (common Meta issue: template not found in specific locale)
    if ($httpCode === 400 && $idioma === 'es_CL' && isset($decoded['error']['code']) && $decoded['error']['code'] == 132001) {
        $logRetry = "[$timestamp] RETRYING WITH 'es' for $destinatario...\n";
        file_put_contents($logFile, $logRetry, FILE_APPEND);
        return enviarWhatsApp($destinatario, $templateNombre, 'es', $variables);
    }

    if ($error) {
        return ['success' => false, 'error' => $error];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'data' => $decoded, 'token_debug' => $token];
    } else {
        return ['success' => false, 'error' => $decoded ?? $response, 'code' => $httpCode, 'token_debug' => $token];
    }
}
?>
