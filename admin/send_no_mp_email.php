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
$subject = "💳 Cobra en piloto automático: Conecta Mercado Pago a tu Academia";
$body = "
<div style='font-family: \"Inter\", \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #f8fafc; max-width: 600px; margin: 0 auto; background-color: #020617; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.5); border: 1px solid #1e293b;'>
    
    <!-- HEADER -->
    <div style='background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); padding: 40px 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.05);'>
        <img src='https://api.padelmanager.cl/assets/images/logo-circular.png' alt='OverDrive' style='width: 70px; margin-bottom: 20px; box-shadow: 0 0 20px rgba(204,255,0,0.2); border-radius: 50%;'>
        <h1 style='color: #ffffff; margin: 0; font-size: 26px; letter-spacing: 2px; text-transform: uppercase; font-weight: 900;'>Over<span style='color: #ccff00;'>Drive</span></h1>
    </div>
    
    <!-- CONTENT -->
    <div style='padding: 40px 35px;'>
        <h2 style='color: #ffffff; font-size: 22px; margin-top: 0; letter-spacing: -0.5px;'>¡Hola $nombre! Activa los cobros automáticos 💳</h2>
        
        <p style='color: #94a3b8; font-size: 15px;'>Queremos que tu academia funcione de manera eficiente y moderna. Para lograrlo, <strong style='color: #ffffff;'>necesitas conectar tu cuenta de Mercado Pago</strong> en OverDrive.</p>
        
        <p style='color: #94a3b8; font-size: 15px;'>Al vincular tu cuenta, tus alumnos podrán pagar con tarjetas de débito, crédito o transferencias directamente desde su app al momento de agendar. Los ingresos se acreditarán inmediatamente en tu cuenta de Mercado Pago sin cobros manuales ni tener que revisar transferencias.</p>
        
        <!-- CALL TO ACTION BOX -->
        <div style='background-color: rgba(30, 41, 59, 0.5); border: 1px solid rgba(204, 255, 0, 0.2); border-left: 4px solid #ccff00; padding: 30px; margin: 35px 0; border-radius: 12px;'>
            <h3 style='margin-top: 0; color: #ccff00; font-size: 18px;'>⚡ Beneficios de vincular Mercado Pago:</h3>
            <ul style='padding-left: 20px; margin-bottom: 30px; color: #cbd5e1; font-size: 14px;'>
                <li style='margin-bottom: 12px;'><strong>Cero Cobranza Manual:</strong> El sistema bloquea el agendamiento si el pack no está pagado.</li>
                <li style='margin-bottom: 12px;'><strong>Split Automático:</strong> Recibes tu dinero directamente de forma segura.</li>
                <li style='margin-bottom: 12px;'><strong>Medios de Pago:</strong> Ofrece cuotas con tarjeta de crédito a tus alumnos.</li>
            </ul>
            
            <div style='text-align: center;'>
                <a href='https://padelblox.cl' 
                   style='background-color: #ccff00; color: #000000; padding: 16px 32px; text-decoration: none; font-weight: 800; border-radius: 8px; display: inline-block; font-size: 14px; box-shadow: 0 4px 15px rgba(204,255,0,0.3); text-transform: uppercase; letter-spacing: 1px;'>
                   VINCULAR MERCADO PAGO AHORA
                </a>
            </div>
        </div>

        <p style='color: #94a3b8; font-size: 15px;'>La integración es 100% segura y respaldada por Mercado Pago. Si tienes dudas sobre los porcentajes de comisión o la creación de tu cuenta de Mercado Pago, responde a este correo y te ayudamos.</p>
        
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
    echo json_encode(["success" => true, "message" => "Correo de incentivo de Mercado Pago enviado con éxito a $email"]);
} else {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Error al enviar el correo: " . $resultMail['error']]);
}
?>
