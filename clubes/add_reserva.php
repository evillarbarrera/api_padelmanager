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

$cancha_id = $data['cancha_id'] ?? 0;
$fecha = $data['fecha'] ?? '';
$hora_inicio = $data['hora_inicio'] ?? '';
$hora_fin = $data['hora_fin'] ?? '';

// Players and their IDs/Names
$players = [
    ['id' => $data['jugador_id'] ?? null, 'name' => $data['nombre_externo'] ?? ''],
    ['id' => $data['jugador2_id'] ?? null, 'name' => $data['nombre_externo2'] ?? ''],
    ['id' => $data['jugador3_id'] ?? null, 'name' => $data['nombre_externo3'] ?? ''],
    ['id' => $data['jugador4_id'] ?? null, 'name' => $data['nombre_externo4'] ?? '']
];

if (!$cancha_id || !$fecha || !$hora_inicio) {
    http_response_code(400);
    echo json_encode(["error" => "Faltan datos obligatorios"]);
    exit;
}

// 1. Validar disponibilidad (Overlap check: existing_start < new_end AND existing_end > new_start)
$sqlCheck = "SELECT id FROM reservas_cancha 
             WHERE cancha_id = ? AND fecha = ? 
             AND hora_inicio < ? AND hora_fin > ?
             AND estado != 'Cancelada'";
$stmtCheck = $conn->prepare($sqlCheck);
$stmtCheck->bind_param("isss", $cancha_id, $fecha, $hora_fin, $hora_inicio);
$stmtCheck->execute();
if ($stmtCheck->get_result()->num_rows > 0) {
    http_response_code(409);
    echo json_encode(["error" => "La cancha ya está reservada en ese horario"]);
    exit;
}

// 2. Insertar reserva
$estado = $data['estado'] ?? 'Confirmada';

$sqlInsert = "INSERT INTO reservas_cancha 
              (cancha_id, usuario_id, jugador2_id, jugador3_id, jugador4_id, 
               nombre_externo, nombre_externo2, nombre_externo3, nombre_externo4, 
               fecha, hora_inicio, hora_fin, precio, estado) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($sqlInsert);
$stmt->bind_param("iiiiisssssssds", 
    $cancha_id, 
    $players[0]['id'], $players[1]['id'], $players[2]['id'], $players[3]['id'],
    $players[0]['name'], $players[1]['name'], $players[2]['name'], $players[3]['name'],
    $fecha, $hora_inicio, $hora_fin, $data['precio'], $estado
);

try {
    if ($stmt->execute()) {
        $resID = $conn->insert_id;

        // 3. Notificaciones por correo
        require_once "../system/mail_service.php";
        
        $notificationData = [];
        foreach ($players as $p) {
            if ($p['id']) {
                $userRes = $conn->query("SELECT nombre, usuario FROM usuarios WHERE id = " . intval($p['id']));
                if ($userRes && $u = $userRes->fetch_assoc()) {
                    $notificationData[] = ['email' => $u['usuario'], 'nombre' => $u['nombre']];
                }
            }
        }

        // Get Admin Email
        $adminRes = $conn->query("SELECT u.nombre, u.usuario FROM clubes c JOIN usuarios u ON c.admin_id = u.id JOIN canchas ca ON ca.club_id = c.id WHERE ca.id = " . intval($cancha_id));
        if ($adminRes && $admin = $adminRes->fetch_assoc()) {
            $notificationData[] = ['email' => $admin['usuario'], 'nombre' => $admin['nombre']];
        }

        $fechaFmt = date("d/m/Y", strtotime($fecha));
        $horaFmt = substr($hora_inicio, 0, 5);
        $subject = "🎾 Reserva Confirmada - $fechaFmt $horaFmt";
        
        foreach ($notificationData as $target) {
            $email = $target['email'];
            $nombre = $target['nombre'];
            
            $body = "
            <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                <h2 style='color: #111;'>🎾 ¡Reserva de Cancha Confirmada!</h2>
                <p>Hola <strong>$nombre</strong>,</p>
                <p>Se ha confirmado una reserva de cancha en el sistema.</p>
                <div style='background: #f4f4f4; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                    <p style='margin: 5px 0;'><strong>Fecha:</strong> $fechaFmt</p>
                    <p style='margin: 5px 0;'><strong>Horario:</strong> $horaFmt - $hora_fin</p>
                    <p style='margin: 5px 0;'><strong>Cancha:</strong> Cancha ID $cancha_id</p>
                </div>
                <p>¡Te esperamos!</p>
                <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
                <p style='font-size: 12px; color: #888;'>Padel Manager - Gestión Integral de Padel</p>
            </div>";

            if (!empty($email)) {
                enviarCorreoSMTP($email, $subject, $body);
            }
        }

        echo json_encode(["success" => true, "id" => $resID]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Error al guardar la reserva: " . $conn->error]);
    }
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    $errorMsg = $e->getMessage();
    
    // Friendly constraint error message
    if (strpos($errorMsg, 'FOREIGN KEY') !== false) {
        if (strpos($errorMsg, 'cancha_id') !== false) {
            $errorMsg = "La cancha seleccionada no existe.";
        } else if (strpos($errorMsg, 'usuario_id') !== false || strpos($errorMsg, 'jugador') !== false) {
            $errorMsg = "Uno de los jugadores seleccionados no existe en la base de datos.";
        }
    }

    echo json_encode(["error" => "Error al guardar: " . $errorMsg]);
}
?>
