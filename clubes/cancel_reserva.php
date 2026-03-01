<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$reserva_id = $data['reserva_id'] ?? 0;

if (!$reserva_id) {
    http_response_code(400);
    echo json_encode(["error" => "ID de reserva necesario"]);
    exit;
}

// 1. Obtener datos antes de cancelar para el correo
$sql = "SELECT * FROM reservas_cancha WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $reserva_id);
$stmt->execute();
$reserva = $stmt->get_result()->fetch_assoc();

if (!$reserva) {
    http_response_code(404);
    echo json_encode(["error" => "Reserva no encontrada"]);
    exit;
}

// 2. Cancelar
$sqlUpdate = "UPDATE reservas_cancha SET estado = 'Cancelada' WHERE id = ?";
$stmtUpdate = $conn->prepare($sqlUpdate);
$stmtUpdate->bind_param("i", $reserva_id);

if ($stmtUpdate->execute()) {
    // 3. Notificar
    require_once "../system/mail_service.php";
    
    $player_ids = [$reserva['usuario_id'], $reserva['jugador2_id'], $reserva['jugador3_id'], $reserva['jugador4_id']];
    $notificationData = [];
    foreach ($player_ids as $pid) {
        if ($pid) {
            $uRes = $conn->query("SELECT nombre, usuario FROM usuarios WHERE id = " . intval($pid));
            if ($u = $uRes->fetch_assoc()) {
                $notificationData[] = ['email' => $u['usuario'], 'nombre' => $u['nombre']];
            }
        }
    }

    // Notificar también al Administrador del Club
    $adminRes = $conn->query("SELECT u.nombre, u.usuario FROM clubes c JOIN usuarios u ON c.admin_id = u.id JOIN canchas ca ON ca.club_id = c.id WHERE ca.id = " . intval($reserva['cancha_id']));
    if ($admin = $adminRes->fetch_assoc()) {
        $notificationData[] = ['email' => $admin['usuario'], 'nombre' => $admin['nombre']];
    }

    $fechaFmt = date("d/m/Y", strtotime($reserva['fecha']));
    $horaFmt = substr($reserva['hora_inicio'], 0, 5);
    $subject = "🚫 Reserva Cancelada - $fechaFmt $horaFmt";

    foreach ($notificationData as $target) {
        $email = $target['email'];
        $nombre = $target['nombre'];

        $body = "
        <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <h2 style='color: #d32f2f;'>🚫 Reserva Cancelada</h2>
            <p>Hola <strong>$nombre</strong>,</p>
            <p>Se ha cancelado una reserva de cancha en el sistema.</p>
            <div style='background: #f4f4f4; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                <p style='margin: 5px 0;'><strong>Fecha:</strong> $fechaFmt</p>
                <p style='margin: 5px 0;'><strong>Horario:</strong> $horaFmt</p>
                <p style='margin: 5px 0;'><strong>Cancha:</strong> Cancha ID " . $reserva['cancha_id'] . "</p>
            </div>
            <p>El horario ha vuelto a quedar disponible.</p>
            <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
            <p style='font-size: 12px; color: #888;'>Padel Manager - Gestión Integral de Padel</p>
        </div>";

        if (!empty($email)) {
            enviarCorreoSMTP($email, $subject, $body);
        }
    }

    echo json_encode(["success" => true]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al cancelar"]);
}
?>
