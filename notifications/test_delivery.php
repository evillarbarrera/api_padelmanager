<?php
require_once '../db.php';
require_once 'notificaciones_helper.php';

header('Content-Type: application/json');

$userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;
$type = isset($_GET['type']) ? $_GET['type'] : 'test';

if (!$userId) {
    echo json_encode([
        "error" => "Falta user_id. Uso: test_delivery.php?user_id=TU_ID&type=daily_tip|evaluacion|cancelacion|test",
        "tip" => "Puedes ver tu ID en la base de datos o en el localStorage de la App."
    ]);
    exit;
}

$results = [
    "user_id" => $userId,
    "type_requested" => $type,
    "timestamp" => date('Y-m-d H:i:s')
];

switch ($type) {
    case 'daily_tip':
        // Obtener el tip de hoy
        $resTip = $conn->query("SELECT titulo, mensaje FROM tips_diarios_ia ORDER BY fecha DESC LIMIT 1");
        $tip = $resTip->fetch_assoc();
        $titulo = $tip['titulo'] ?? "🎾 Consejo del Día";
        $mensaje = $tip['mensaje'] ?? "¡Sigue entrenando para mejorar tu nivel!";
        $results['notification'] = "Enviando Consejo Diario";
        notifyUser($conn, $userId, $titulo, $mensaje, 'daily_tip');
        break;

    case 'evaluacion':
        $titulo = "📊 Nueva Evaluación Disponible";
        $mensaje = "Tu entrenador ha subido tu evaluación técnica. ¡Revísala ahora!";
        $results['notification'] = "Enviando Nueva Evaluación";
        notifyUser($conn, $userId, $titulo, $mensaje, 'nueva_evaluacion');
        break;

    case 'cancelacion':
        $titulo = "❌ Clase Cancelada";
        $mensaje = "Tu clase de mañana a las 10:00 ha sido cancelada por el club.";
        $results['notification'] = "Enviando Cancelación";
        notifyUser($conn, $userId, $titulo, $mensaje, 'cancelacion');
        break;

    default:
        $titulo = "🚀 Prueba de Notificación";
        $mensaje = "Si lees esto, el sistema de Push de Padel Manager está funcionando.";
        $results['notification'] = "Enviando Prueba General";
        notifyUser($conn, $userId, $titulo, $mensaje, 'test');
        break;
}

// Verificar si el usuario tiene tokens
$resTokens = $conn->query("SELECT COUNT(*) as total FROM fcm_tokens WHERE user_id = $userId");
$tokenCount = $resTokens->fetch_assoc()['total'];
$results['device_tokens_found'] = intval($tokenCount);

if ($results['device_tokens_found'] === 0) {
    $results['warning'] = "El usuario no tiene tokens registrados. Abre la App y logueate de nuevo para registrar el dispositivo.";
}

echo json_encode($results, JSON_PRETTY_PRINT);
