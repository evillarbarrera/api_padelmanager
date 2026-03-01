<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$headers = getallheaders();
$auth = $headers['Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if ($auth !== 'Bearer ' . base64_encode("1|padel_academy")) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$pack_jugadores_id = $data['pack_jugadores_id'] ?? null;
$email_invitado = $data['email_invitado'] ?? null;

if (!$pack_jugadores_id || !$email_invitado) {
    http_response_code(400);
    echo json_encode(["error" => "Faltan datos (pack_jugadores_id, email_invitado)"]);
    exit;
}

require_once "../db.php";

try {
    // 1. Buscar usuario invitado por email
    // En este proyecto el email se guarda en la columna 'usuario'
    $stmtUser = $conn->prepare("SELECT id, nombre FROM usuarios WHERE usuario = ?");
    if (!$stmtUser) throw new Exception("Prepare user failed: " . $conn->error);
    
    $stmtUser->bind_param("s", $email_invitado);
    $stmtUser->execute();
    $resUser = $stmtUser->get_result()->fetch_assoc();

    if (!$resUser) {
        http_response_code(404);
        echo json_encode(["error" => "Usuario no encontrado con ese email. Debe registrarse primero en la app."]);
        exit;
    }

    $invitado_id = $resUser['id'];

    // 2. Verificar cupos disponibles en el pack
    $sqlCheck = "
        SELECT 
            pk.cantidad_personas,
            pk.nombre as pack_nombre,
            (SELECT COUNT(*) FROM pack_jugadores_adicionales WHERE pack_jugadores_id = pj.id AND estado != 'cancelado') as ocupados
        FROM pack_jugadores pj
        JOIN packs pk ON pj.pack_id = pk.id
        WHERE pj.id = ?
    ";
    $stmtCheck = $conn->prepare($sqlCheck);
    if (!$stmtCheck) throw new Exception("Prepare check failed: " . $conn->error);
    
    $stmtCheck->bind_param("i", $pack_jugadores_id);
    $stmtCheck->execute();
    $info = $stmtCheck->get_result()->fetch_assoc();

    if (!$info) {
        http_response_code(404);
        echo json_encode(["error" => "Pack comprado no encontrado."]);
        exit;
    }

    $max_adicionales = $info['cantidad_personas'] - 1;

    if ($info['ocupados'] >= $max_adicionales) {
        http_response_code(400);
        echo json_encode(["error" => "No quedan cupos libres en este pack (Max adicionales: $max_adicionales)."]);
        exit;
    }

    // 3. Verificar que no esté ya asignado (activo o pendiente)
    $sqlExist = "SELECT id FROM pack_jugadores_adicionales WHERE pack_jugadores_id = ? AND jugador_id = ? AND estado != 'cancelado'";
    $stmtExist = $conn->prepare($sqlExist);
    if (!$stmtExist) throw new Exception("Prepare exist failed: " . $conn->error);
    
    $stmtExist->bind_param("ii", $pack_jugadores_id, $invitado_id);
    $stmtExist->execute();
    if ($stmtExist->get_result()->fetch_assoc()) {
        http_response_code(400);
        echo json_encode(["error" => "Este usuario ya tiene una invitación activa o ya es parte de este pack."]);
        exit;
    }

    // 4. Generar Token y Guardar como Pendiente
    $token = bin2hex(random_bytes(16));
    $sqlInsert = "INSERT INTO pack_jugadores_adicionales (pack_jugadores_id, jugador_id, estado, token) VALUES (?, ?, 'pendiente', ?)";
    $stmtInsert = $conn->prepare($sqlInsert);
    if (!$stmtInsert) throw new Exception("Prepare insert failed: " . $conn->error);
    
    $stmtInsert->bind_param("iis", $pack_jugadores_id, $invitado_id, $token);

    if ($stmtInsert->execute()) {
        require_once "../system/mail_service.php";
        
        $confirmLink = "https://www.padelmanager.cl/#/unete?token=" . $token;
        $to = $email_invitado;
        $subject = "🎾 Invitación a unirte a un Pack - Padel Manager";
        
        $message = "
        <html>
        <body style='margin: 0; padding: 0; background-color: #0f172a; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif;'>
          <table width='100%' border='0' cellspacing='0' cellpadding='0' style='background-color: #0f172a; padding: 40px 20px;'>
            <tr>
              <td align='center'>
                <div style='max-width: 500px; width: 100%; background-color: #1e293b; border-radius: 20px; border: 1px solid rgba(255,255,255,0.1); overflow: hidden; box-shadow: 0 20px 40px rgba(0,0,0,0.4);'>
                  <div style='background-color: #ccff00; padding: 30px; text-align: center;'>
                    <span style='font-size: 50px;'>🎾</span>
                    <h1 style='margin: 10px 0 0; color: #000; font-size: 24px; font-weight: 900; text-transform: uppercase;'>Padel Manager</h1>
                  </div>
                  <div style='padding: 40px 30px; text-align: left; color: #ffffff;'>
                    <h2 style='margin: 0 0 20px; font-size: 20px; font-weight: 700; color: #ccff00;'>¡Te han invitado a un equipo!</h2>
                    <p style='margin: 0 0 10px; font-size: 16px; color: #cbd5e1;'>Hola <strong>" . htmlspecialchars($resUser['nombre']) . "</strong>,</p>
                    <p style='margin: 0 0 25px; font-size: 16px; color: #cbd5e1; line-height: 1.6;'>
                      Tu compañero de pista te ha invitado a compartir un pack de entrenamiento y empezar a mejorar tu nivel juntos.
                    </p>
                    
                    <div style='background-color: rgba(255,255,255,0.05); border-left: 4px solid #ccff00; padding: 20px; margin-bottom: 30px; border-radius: 4px;'>
                      <p style='margin: 0 0 10px; font-size: 14px; color: #94a3b8; text-transform: uppercase; font-weight: 800; letter-spacing: 0.5px;'>Detalles del Pack</p>
                      <p style='margin: 0; font-size: 18px; color: #f8fafc; font-weight: 600;'>" . htmlspecialchars($info['pack_nombre']) . "</p>
                    </div>

                    <div style='text-align: center; margin-bottom: 30px;'>
                      <a href='$confirmLink' style='display: inline-block; background-color: #ccff00; color: #000; padding: 18px 35px; text-decoration: none; font-weight: 900; font-size: 16px; border-radius: 12px; box-shadow: 0 10px 20px rgba(204, 255, 0, 0.2);'>ACEPTAR INVITACIÓN</a>
                    </div>

                    <p style='margin: 0; font-size: 14px; color: #94a3b8; text-align: center;'>
                      Si no esperabas esta invitación, puedes ignorar este correo.
                    </p>
                  </div>
                  <div style='background-color: rgba(0,0,0,0.2); padding: 25px; text-align: center;'>
                    <p style='margin: 0 0 10px; font-size: 12px; color: #64748b;'>
                      ¿El botón no funciona? Copia este enlace en tu navegador:
                    </p>
                    <p style='margin: 0; font-size: 12px;'>
                      <a href='$confirmLink' style='color: #ccff00; text-decoration: none; word-break: break-all;'>$confirmLink</a>
                    </p>
                  </div>
                </div>
                <p style='margin-top: 30px; font-size: 12px; color: #475569; text-align: center;'>
                  &copy; " . date('Y') . " Padel Manager Academy. Todos los derechos reservados.
                </p>
              </td>
            </tr>
          </table>
        </body>
        </html>";
        
        $resMail = enviarCorreoSMTP($to, $subject, $message);

        echo json_encode([
            "success" => true, 
            "message" => "Invitación enviada. El jugador recibirá un correo para confirmar.",
            "debug_mail" => $resMail['success'] ? "Sent" : "Failed"
        ]);
    } else {
        throw new Exception("Execute insert failed: " . $stmtInsert->error);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Error interno: " . $e->getMessage()]);
}
?>
