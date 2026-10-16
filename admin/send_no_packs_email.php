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

// 2. Preparar el contenido del correo
$subject = "🚀 Ofrece tus clases de forma profesional: Crea tus Packs en OverDrive";
$body = "
<div style='font-family: \"Inter\", \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #f8fafc; max-width: 600px; margin: 0 auto; background-color: #020617; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.5); border: 1px solid #1e293b;'>
    
    <!-- HEADER -->
    <div style='background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); padding: 40px 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.05);'>
        <img src='https://api.padelmanager.cl/assets/images/logo-circular.png' alt='OverDrive' style='width: 70px; margin-bottom: 20px; box-shadow: 0 0 20px rgba(204,255,0,0.2); border-radius: 50%;'>
        <h1 style='color: #ffffff; margin: 0; font-size: 26px; letter-spacing: 2px; text-transform: uppercase; font-weight: 900;'>Over<span style='color: #ccff00;'>Drive</span></h1>
    </div>
    
    <!-- CONTENT -->
    <div style='padding: 40px 35px;'>
        <h2 style='color: #ffffff; font-size: 22px; margin-top: 0; letter-spacing: -0.5px;'>¡Hola $nombre! ¿Listo para lanzar tu academia? 🥎</h2>
        
        <p style='color: #94a3b8; font-size: 15px;'>Notamos que ya tienes tu cuenta configurada en OverDrive, pero <strong style='color: #ffffff;'>aún no has creado tu primer Pack de Clases</strong>. Recuerda que los alumnos solo pueden ver tu perfil y agendar clases si tienes al menos un pack activo.</p>
        
        <p style='color: #94a3b8; font-size: 15px;'>El uso de packs de clases te permite asegurar tu flujo de caja cobrando por adelantado (por ejemplo, packs de 4, 8 o 12 clases), lo cual reduce las inasistencias y mejora tu planificación mensual.</p>
        
        <!-- CALL TO ACTION BOX -->
        <div style='background-color: rgba(30, 41, 59, 0.5); border: 1px solid rgba(204, 255, 0, 0.2); border-left: 4px solid #ccff00; padding: 30px; margin: 35px 0; border-radius: 12px;'>
            <h3 style='margin-top: 0; color: #ccff00; font-size: 18px;'>⚡ Crea tu primer Pack en 3 pasos simples:</h3>
            <ol style='padding-left: 20px; margin-bottom: 30px; color: #cbd5e1; font-size: 14px;'>
                <li style='margin-bottom: 12px;'>Inicia sesión en tu panel de OverDrive.</li>
                <li style='margin-bottom: 12px;'>Ve a la sección <strong style='color:#fff;'>Packs de Clases</strong> y haz clic en <strong style='color:#ccff00;'>'Crear Nuevo Pack'</strong>.</li>
                <li style='margin-bottom: 12px;'>Ingresa el nombre, precio, número de sesiones y actívalo.</li>
            </ol>
            
            <div style='text-align: center;'>
                <a href='https://padelblox.cl' 
                   style='background-color: #ccff00; color: #000000; padding: 16px 32px; text-decoration: none; font-weight: 800; border-radius: 8px; display: inline-block; font-size: 14px; box-shadow: 0 4px 15px rgba(204,255,0,0.3); text-transform: uppercase; letter-spacing: 1px;'>
                   CREAR MI PRIMER PACK AHORA
                </a>
            </div>
        </div>

        <p style='color: #94a3b8; font-size: 15px;'>Si necesitas ayuda con los precios de tus packs o quieres estructurar tus planes mensuales con nosotros, responde a este correo directamente y programamos una llamada rápida de 10 minutos.</p>
        
        <!-- FOOTER SIGNATURE -->
        <div style='margin-top: 40px; border-top: 1px solid #1e293b; padding-top: 30px;'>
            <p style='margin: 0; color: #ffffff; font-weight: 700;'>Emmanuel Villar</p>
            <p style='margin: 3px 0; color: #94a3b8; font-size: 13px;'>Founder & CEO, OverDrive Academy</p>
            <a href='mailto:ejvillarb@padelblox.cl' style='color: #ccff00; text-decoration: none; font-size: 13px;'>ejvillarb@padelblox.cl</a>
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
    echo json_encode(["success" => true, "message" => "Correo de incentivo de packs enviado con éxito a $email"]);
} else {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Error al enviar el correo: " . $resultMail['error']]);
}
?>
