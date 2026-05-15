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
$reserva_id = $data['id'] ?? null;

if (!$reserva_id) {
    http_response_code(400);
    echo json_encode(["error" => "ID de reserva no proporcionado"]);
    exit;
}

try {
    if (!$conn) {
        throw new Exception("No hay conexión con la base de datos");
    }

    // Actualizar el estado a cancelado
    $stmt = $conn->prepare("UPDATE reservas SET estado = 'cancelado' WHERE id = ?");
    $stmt->bind_param("i", $reserva_id);
    $resExec = $stmt->execute();

    // Antes de terminar, notificar a los alumnos
    require_once "../notifications/notificaciones_helper.php";
    
    // Obtener detalles de la reserva para la notificación
    $stmtD = $conn->prepare("
        SELECT r.fecha, r.hora_inicio, u.nombre as entrenador_nombre, r.tipo, r.pack_id
        FROM reservas r
        JOIN usuarios u ON u.id = r.entrenador_id
        WHERE r.id = ?
    ");
    $stmtD->bind_param("i", $reserva_id);
    $stmtD->execute();
    $resDetails = $stmtD->get_result()->fetch_assoc();

    if ($resDetails) {
        $fechaFmt = date("d/m/Y", strtotime($resDetails['fecha']));
        $horaFmt = substr($resDetails['hora_inicio'], 0, 5);
        $nomEntrenador = $resDetails['entrenador_nombre'];
        
        // Obtener alumnos de la reserva
        $stmtA = $conn->prepare("SELECT jugador_id FROM reserva_jugadores WHERE reserva_id = ?");
        $stmtA->bind_param("i", $reserva_id);
        $stmtA->execute();
        $resAlumnos = $stmtA->get_result();

        while ($alum = $resAlumnos->fetch_assoc()) {
            $msg = "Tu clase con $nomEntrenador el $fechaFmt a las $horaFmt ha sido cancelada.";
            notifyUser($conn, $alum['jugador_id'], "❌ Clase Cancelada", $msg, 'clase_cancelada');
        }
    }

    if ($resExec) {
        echo json_encode([
            "success" => true,
            "message" => "Reserva cancelada correctamente. Créditos liberados."
        ]);
    } else {
        throw new Exception("No se pudo actualizar la reserva");
    }

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(["error" => $e->getMessage()]);
}
?>
