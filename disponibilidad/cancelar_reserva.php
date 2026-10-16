<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, X-Authorization, Content-Type");
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../auth/auth_helper.php";
$tokenUserId = validateToken();
if (!$tokenUserId) {
    sendUnauthorized();
}

header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, X-Authorization, Content-Type");
    http_response_code(200);
    exit;
}

// Authorization


require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$reserva_id = $data['id'] ?? ($data['reserva_id'] ?? null);
$jugador_id_to_remove = $data['jugador_id'] ?? null;

if (!$reserva_id) {
    http_response_code(400);
    echo json_encode(["error" => "ID de reserva no proporcionado"]);
    exit;
}

try {
    if (!$conn) {
        throw new Exception("No hay conexión con la base de datos");
    }

    require_once "../notifications/notificaciones_helper.php";
    require_once "../system/mail_service.php";

    if ($jugador_id_to_remove) {
        // --- REMOVER SOLO UN JUGADOR ---
        $stmtDel = $conn->prepare("DELETE FROM reserva_jugadores WHERE reserva_id = ? AND jugador_id = ?");
        $stmtDel->bind_param("ii", $reserva_id, $jugador_id_to_remove);
        $resExec = $stmtDel->execute();
        
        if ($resExec) {
            echo json_encode(["success" => true, "message" => "Jugador removido de la clase."]);
            
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            // Notificar solo a ese jugador
            $stmtD = $conn->prepare("SELECT r.fecha, r.hora_inicio, u.nombre as entrenador_nombre FROM reservas r JOIN usuarios u ON u.id = r.entrenador_id WHERE r.id = ?");
            $stmtD->bind_param("i", $reserva_id);
            $stmtD->execute();
            $resDetails = $stmtD->get_result()->fetch_assoc();

            if ($resDetails) {
                $fechaFmt = date("d/m/Y", strtotime($resDetails['fecha']));
                $horaFmt = substr($resDetails['hora_inicio'], 0, 5);
                $nomEntrenador = $resDetails['entrenador_nombre'];
                
                $msg = "Has sido removido de la clase con $nomEntrenador el $fechaFmt a las $horaFmt.";
                notifyUser($conn, $jugador_id_to_remove, "❌ Clase Modificada", $msg, 'clase_cancelada');
                
                // Email
                $stmtU = $conn->prepare("SELECT nombre, usuario FROM usuarios WHERE id = ?");
                $stmtU->bind_param("i", $jugador_id_to_remove);
                $stmtU->execute();
                $uAlum = $stmtU->get_result()->fetch_assoc();
                if ($uAlum && !empty($uAlum['usuario'])) {
                    $subject = "❌ Removido de la Clase - $fechaFmt";
                    $body = "Hola <strong>{$uAlum['nombre']}</strong>, se te ha removido de la clase del $fechaFmt a las $horaFmt con $nomEntrenador.";
                    enviarCorreoSMTP($uAlum['usuario'], $subject, $body);
                }
            }
        } else {
            throw new Exception("No se pudo remover al jugador");
        }
    } else {
        // --- CANCELAR TODA LA RESERVA ---
        $stmt = $conn->prepare("UPDATE reservas SET estado = 'cancelado' WHERE id = ?");
        $stmt->bind_param("i", $reserva_id);
        $resExec = $stmt->execute();

        if ($resExec) {
            echo json_encode(["success" => true, "message" => "Reserva cancelada correctamente."]);

            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            // Notificar a todos los alumnos (Lógica existente)
            $stmtD = $conn->prepare("SELECT r.fecha, r.hora_inicio, u.nombre as entrenador_nombre FROM reservas r JOIN usuarios u ON u.id = r.entrenador_id WHERE r.id = ?");
            $stmtD->bind_param("i", $reserva_id);
            $stmtD->execute();
            $resDetails = $stmtD->get_result()->fetch_assoc();

            if ($resDetails) {
                $fechaFmt = date("d/m/Y", strtotime($resDetails['fecha']));
                $horaFmt = substr($resDetails['hora_inicio'], 0, 5);
                $nomEntrenador = $resDetails['entrenador_nombre'];
                
                $stmtA = $conn->prepare("SELECT jugador_id FROM reserva_jugadores WHERE reserva_id = ?");
                $stmtA->bind_param("i", $reserva_id);
                $stmtA->execute();
                $resAlumnos = $stmtA->get_result();

                while ($alum = $resAlumnos->fetch_assoc()) {
                    $msg = "Tu clase con $nomEntrenador el $fechaFmt a las $horaFmt ha sido cancelada.";
                    notifyUser($conn, $alum['jugador_id'], "❌ Clase Cancelada", $msg, 'clase_cancelada');
                    
                    // Email
                    $stmtU = $conn->prepare("SELECT nombre, usuario FROM usuarios WHERE id = ?");
                    $stmtU->bind_param("i", $alum['jugador_id']);
                    $stmtU->execute();
                    $uAlum = $stmtU->get_result()->fetch_assoc();
                    if ($uAlum && !empty($uAlum['usuario'])) {
                        $subject = "❌ Clase Cancelada - $fechaFmt";
                        $body = "Tu clase con $nomEntrenador el $fechaFmt ha sido cancelada.";
                        enviarCorreoSMTP($uAlum['usuario'], $subject, $body);
                    }
                }
            }
        } else {
            throw new Exception("No se pudo cancelar la reserva");
        }
    }

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(["error" => $e->getMessage()]);
}
?>
