<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";
require_once "../auth/auth_helper.php";
require_once "../system/mail_service.php";

if (!validateToken()) {
    sendUnauthorized();
}

$data = json_decode(file_get_contents("php://input"), true);
$entrenador_id = $data['entrenador_id'] ?? null;

if (!$entrenador_id) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "ID de entrenador es obligatorio"]);
    exit;
}

// 1. Obtener datos del entrenador
$sql = "SELECT nombre, usuario FROM usuarios WHERE id = ? AND rol = 'entrenador'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $entrenador_id);
$stmt->execute();
$result = $stmt->get_result();
$entrenador = $result->fetch_assoc();

if (!$entrenador) {
    http_response_code(404);
    echo json_encode(["success" => false, "error" => "Entrenador no encontrado"]);
    exit;
}

$nombre = $entrenador['nombre'];
$email = $entrenador['usuario']; // El correo es el usuario

// 2. Preparar el contenido del correo (Versión Ventas de Alto Impacto)
$subject = "🚀 Tu Academia de Pádel en piloto automático: ¿Hablamos, $nombre?";
$body = "
<div style='font-family: \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #1a1a1a; max-width: 600px; margin: 0 auto; border: 1px solid #e1e4e8; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.1);'>
    <div style='background: linear-gradient(135deg, #111 0%, #222 100%); padding: 30px; text-align: center;'>
        <img src='https://api.padelmanager.cl/assets/images/logo-circular.png' alt='PadelManager' style='width: 70px; margin-bottom: 15px;'>
        <h1 style='color: #ccff00; margin: 0; font-size: 24px; letter-spacing: 1px; text-transform: uppercase;'>PadelManager</h1>
    </div>
    
    <div style='padding: 40px 35px;'>
        <h2 style='color: #111; font-size: 22px; margin-top: 0;'>¡Hola, $nombre! Bienvenido al siguiente nivel.</h2>
        
        <p>Ya eres parte de <strong>PadelManager</strong>, y eso significa una sola cosa: has decidido profesionalizar tu academia para que crezca sin que tú tengas que estar pegado al teléfono todo el día.</p>
        
        <p>Mi nombre es Emmanuel Villar y he diseñado esta plataforma específicamente para coaches que, como tú, quieren <strong>dominar su mercado</strong> y ofrecer una experiencia premium a sus alumnos.</p>
        
        <div style='background-color: #f8f9fa; border-left: 4px solid #ccff00; padding: 25px; margin: 30px 0; border-radius: 4px;'>
            <h3 style='margin-top: 0; color: #111; font-size: 18px;'>⚡ Sesión de Estrategia: 15 Minutos para Escalar</h3>
            <p style='margin-bottom: 20px;'>No quiero explicarte un manual. Quiero que tengamos una breve videollamada para configurar tu <strong>\"Máquina de Ventas\"</strong>:</p>
            <ul style='padding-left: 20px; margin-bottom: 25px;'>
                <li style='margin-bottom: 10px;'>Cómo automatizar tus cobros con <strong>Mercado Pago</strong> (para que nunca más tengas que cobrar manualmente).</li>
                <li style='margin-bottom: 10px;'>Estrategia de <strong>Packs de Clases</strong> que aseguran tu flujo de caja.</li>
                <li style='margin-bottom: 10px;'>Cómo usar la <strong>App del Alumno</strong> para fidelizar y dar seguimiento profesional.</li>
            </ul>
            
            <div style='text-align: center;'>
                <a href='mailto:ejvillarb@padelmanager.cl?subject=Sesión de Estrategia PadelManager - $nombre&body=Hola Emmanuel, me interesa la sesión de estrategia. Mi horario disponible es:' 
                   style='background-color: #ccff00; color: #111; padding: 15px 30px; text-decoration: none; font-weight: 900; border-radius: 8px; display: inline-block; font-size: 14px; box-shadow: 0 4px 6px rgba(204,255,0,0.2);'>
                   RESERVAR MI SESIÓN AHORA
                </a>
            </div>
        </div>

        <p>Hoy en día, el pádel no solo se juega en la cancha, se gestiona con tecnología. Estoy aquí para ayudarte a que tu academia sea la más moderna de tu zona.</p>
        
        <p>¿Qué te parece si coordinamos? Respóndeme este correo y hagámoslo realidad.</p>
        
        <p style='margin-bottom: 0; border-top: 1px solid #eee; padding-top: 25px;'>Nos vemos en la cima,</p>
        <p style='margin-top: 5px;'>
            <strong>Emmanuel Villar</strong><br>
            <span style='color: #666; font-size: 13px;'>Founder & CEO, PadelManager Academy</span><br>
            <a href='mailto:ejvillarb@padelmanager.cl' style='color: #1a73e8; text-decoration: none;'>ejvillarb@padelmanager.cl</a>
        </p>
    </div>
    
    <div style='background-color: #111; padding: 20px; text-align: center; font-size: 11px; color: #666; letter-spacing: 1px;'>
        PADELMANAGER ACADEMY CLOUD PLATFORM &copy; " . date('Y') . "
    </div>
</div>";

// 3. Enviar correo
$resultMail = enviarCorreoSMTP($email, $subject, $body);

if ($resultMail['success']) {
    echo json_encode(["success" => true, "message" => "Correo de bienvenida enviado con éxito a $email"]);
} else {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Error al enviar el correo: " . $resultMail['error']]);
}
