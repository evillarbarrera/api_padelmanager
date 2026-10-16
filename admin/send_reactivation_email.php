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
$email = $entrenador['usuario'];

// 2. Preparar el contenido del correo (Versión Reactivación y Cero Alumnos)
$subject = "🚀 Estás a un paso: Crea tu primer alumno en OverDrive";
$body = "
<div style='font-family: \"Inter\", \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #f8fafc; max-width: 600px; margin: 0 auto; background-color: #020617; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.5); border: 1px solid #1e293b;'>
    
    <!-- HEADER -->
    <div style='background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); padding: 40px 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.05);'>
        <img src='https://api.padelmanager.cl/assets/images/logo-circular.png' alt='OverDrive' style='width: 70px; margin-bottom: 20px; box-shadow: 0 0 20px rgba(204,255,0,0.2); border-radius: 50%;'>
        <h1 style='color: #ffffff; margin: 0; font-size: 26px; letter-spacing: 2px; text-transform: uppercase; font-weight: 900;'>Over<span style='color: #ccff00;'>Drive</span></h1>
    </div>
    
    <!-- CONTENT -->
    <div style='padding: 40px 35px;'>
        <h2 style='color: #ffffff; font-size: 22px; margin-top: 0; letter-spacing: -0.5px;'>¡Hola $nombre! Notamos algo en tu perfil... 👀</h2>
        
        <p style='color: #94a3b8; font-size: 15px;'>Hace poco creaste tu academia en OverDrive, pero <strong style='color: #ffffff;'>aún no has registrado a tu primer alumno</strong>. Sabemos que dar el primer paso puede parecer abrumador, pero en realidad, toma menos de 30 segundos.</p>
        
        <p style='color: #94a3b8; font-size: 15px;'>Los entrenadores que usan nuestra plataforma reportan un <strong style='color: #ccff00;'>aumento del 40% en su retención de alumnos</strong> tan solo al usar la aplicación para llevar el control de clases y pagos.</p>
        
        <!-- MISSION BOX -->
        <div style='background-color: rgba(30, 41, 59, 0.5); border: 1px solid rgba(204, 255, 0, 0.2); border-left: 4px solid #ccff00; padding: 30px; margin: 35px 0; border-radius: 12px;'>
            <h3 style='margin-top: 0; color: #ccff00; font-size: 18px; display: flex; align-items: center;'>⚡ Tu Primera Misión:</h3>
            <p style='margin-bottom: 15px; color: #f8fafc; font-size: 14px;'>Para que veas la magia de OverDrive en acción, te retamos a hacer esto hoy mismo:</p>
            
            <ol style='padding-left: 20px; margin-bottom: 30px; color: #cbd5e1; font-size: 14px;'>
                <li style='margin-bottom: 12px;'>Inicia sesión en tu cuenta.</li>
                <li style='margin-bottom: 12px;'>Ve a la pestaña <strong style='color:#fff;'>Mis Alumnos</strong> y haz clic en <span style='color:#ccff00;'>'Registrar Alumno'</span>.</li>
                <li style='margin-bottom: 12px;'>Agrega un alumno de prueba para activar su perfil, estadísticas y pagos.</li>
                <li style='margin-bottom: 12px;'>Fija tu disponibilidad y comienza a gestionar tu <strong style='color:#fff;'>Calendario</strong>.</li>
            </ol>
            
            <div style='text-align: center;'>
                <a href='https://padelblox.cl' 
                   style='background-color: #ccff00; color: #000000; padding: 16px 32px; text-decoration: none; font-weight: 800; border-radius: 8px; display: inline-block; font-size: 14px; box-shadow: 0 4px 15px rgba(204,255,0,0.3); text-transform: uppercase; letter-spacing: 1px;'>
                   INICIAR SESIÓN Y EMPEZAR
                </a>
            </div>
        </div>

        <p style='color: #94a3b8; font-size: 15px;'>¿Necesitas ayuda para arrancar? Si sientes que la tecnología no es lo tuyo, <strong style='color: #ffffff;'>no te preocupes</strong>. Responde a este correo y agendemos una rápida videollamada. Yo mismo te ayudaré a configurar tu primer alumno y automatizar tus pagos.</p>
        
        <p style='color: #ffffff; font-weight: 600; font-size: 16px; margin-top: 30px;'>¡No dejes que tu academia se quede atrás!</p>
        
        <!-- FOOTER SIGNATURE -->
        <div style='margin-top: 40px; border-top: 1px solid #1e293b; padding-top: 30px; display: flex; align-items: center;'>
            <div>
                <p style='margin: 0; color: #ffffff; font-weight: 700;'>Emmanuel Villar</p>
                <p style='margin: 3px 0; color: #94a3b8; font-size: 13px;'>Founder & CEO, OverDrive Academy</p>
                <a href='mailto:ejvillarb@padelblox.cl' style='color: #ccff00; text-decoration: none; font-size: 13px;'>ejvillarb@padelblox.cl</a>
            </div>
        </div>
    </div>
    
    <!-- COPYRIGHT -->
    <div style='background-color: #0f172a; padding: 25px; text-align: center; font-size: 11px; color: #64748b; letter-spacing: 1.5px; border-top: 1px solid #1e293b;'>
        OVERDRIVE ACADEMY CLOUD PLATFORM &copy; " . date('Y') . "
    </div>
</div>";

// 3. Enviar correo
$resultMail = enviarCorreoSMTP($email, $subject, $body);

if ($resultMail['success']) {
    echo json_encode(["success" => true, "message" => "Correo de reactivación enviado con éxito a $email"]);
} else {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Error al enviar el correo: " . $resultMail['error']]);
}
