<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'No data received']);
    exit();
}

$nombre = $data['nombre'] ?? '';
$email = $data['email'] ?? '';
$telefono = $data['telefono'] ?? '';
$academia = $data['academia'] ?? '';

if (empty($nombre) || empty($email)) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

$to = 'ejvillarb@padelmanager.cl';
$subject = 'Nueva solicitud de Capacitación - PadelManager';
$message = "Has recibido una nueva solicitud de capacitación gratuita.\n\n";
$message .= "Nombre: $nombre\n";
$message .= "Email: $email\n";
$message .= "Teléfono: $telefono\n";
$message .= "Academia: $academia\n";

$headers = "From: no-reply@padelmanager.cl\r\n";
$headers .= "Reply-To: $email\r\n";
$headers .= "X-Mailer: PHP/" . phpversion();

$mailSent = mail($to, $subject, $message, $headers);

if ($mailSent) {
    echo json_encode(['success' => true, 'message' => 'Email sent successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to send email']);
}
?>
