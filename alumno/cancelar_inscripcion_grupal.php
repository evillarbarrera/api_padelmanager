<?php
/**
 * Endpoint para cancelar una inscripción a clase grupal.
 * Comportamiento:
 * 1. Elimina (o marca como cancelada) la inscripción en inscripciones_grupales.
 * 2. Libera el cupo en la tabla packs (cupos_ocupados - 1).
 * 3. NO elimina el registro en pack_jugadores, permitiendo al usuario 'gastar' ese crédito en otra fecha.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
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

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$inscripcion_id = $data['inscripcion_id'] ?? 0;
$jugador_id = $data['jugador_id'] ?? 0;

if (!$inscripcion_id || !$jugador_id) {
    http_response_code(400);
    echo json_encode(["error" => "Faltan datos (inscripcion_id, jugador_id)"]);
    exit;
}

$conn->begin_transaction();

try {
    // 1. Obtener info de la inscripción para saber el pack_id
    $sqlInfo = "SELECT pack_id, estado FROM inscripciones_grupales WHERE id = ? AND jugador_id = ?";
    $stmtInfo = $conn->prepare($sqlInfo);
    $stmtInfo->bind_param("ii", $inscripcion_id, $jugador_id);
    $stmtInfo->execute();
    $resInfo = $stmtInfo->get_result()->fetch_assoc();

    if (!$resInfo) {
        throw new Exception("Inscripción no encontrada.");
    }

    if ($resInfo['estado'] !== 'activo') {
        throw new Exception("La inscripción ya está cancelada o inactiva.");
    }

    $pack_id = $resInfo['pack_id'];

    // 2. Cancelar la inscripción
    $sqlCancel = "UPDATE inscripciones_grupales SET estado = 'cancelado' WHERE id = ?";
    $stmtCancel = $conn->prepare($sqlCancel);
    $stmtCancel->bind_param("i", $inscripcion_id);
    $stmtCancel->execute();

    // 3. Liberar el cupo
    $sqlCupo = "UPDATE packs SET cupos_ocupados = cupos_ocupados - 1 WHERE id = ?";
    $stmtCupo = $conn->prepare($sqlCupo);
    $stmtCupo->bind_param("i", $pack_id);
    $stmtCupo->execute();

    // 4. (Opcional) Verificar si baja de 4 personas para cambiar estado a 'pendiente'?
    // Por ahora lo dejamos activo para no causar caos, o lo cambiamos si es un requisito estricto.
    
    // 5. IMPORTANTE: En pack_jugadores ponemos 'sesiones_usadas' = 0 para indicar que tiene 1 crédito disponible?
    // Asumimos que al comprar 'sesiones_usadas' estaba en 0 (crédito disponible) o 1 (usado).
    
    $conn->commit();

    // --- RESPUESTA INMEDIATA ---
    echo json_encode(["success" => true, "message" => "Tu cupo ha sido liberado. Puedes inscribirte en otro horario usando tu crédito vigente."]);

    // Cerramos conexión con el navegador para eliminar lag
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    // --- NOTIFICATIONS START (Background) ---
    require_once "../notifications/whatsapp_service.php";
    require_once "../system/mail_service.php";
    
    // Fetch phones, emails AND names
    $sqlData = "
        SELECT 
            u1.telefono as cel_jugador, u1.nombre as nom_jugador, u1.usuario as email_jugador,
            u2.telefono as cel_entrenador, u2.nombre as nom_entrenador, u2.usuario as email_entrenador,
            p.nombre as pack_nombre, p.dia_semana, p.hora_inicio as pack_hora
        FROM usuarios u1 
        JOIN packs p ON p.id = ?
        JOIN usuarios u2 ON u2.id = p.entrenador_id
        WHERE u1.id = ?
    ";
    
    $stmtP = $conn->prepare($sqlData);
    if ($stmtP) {
        $stmtP->bind_param("ii", $pack_id, $jugador_id);
        $stmtP->execute();
        $resP = $stmtP->get_result()->fetch_assoc();
        
        if ($resP) {
            $dias = ["Domingo", "Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado"];
            $diaNombre = isset($resP['dia_semana']) ? $dias[$resP['dia_semana']] : "Día a confirmar";
            $horaFmt = !empty($resP['pack_hora']) ? substr($resP['pack_hora'], 0, 5) : "--:--";
            
            $nomJugador = $resP['nom_jugador'];
            $nomEntrenador = $resP['nom_entrenador'];
            $emailJugador = $resP['email_jugador'];
            $emailEntrenador = $resP['email_entrenador'];
            
            // 1. WHATSAPP
            // Variables: 1=Day, 2=Time, 3=Player, 4=Coach
            $vars = [$diaNombre, $horaFmt, $nomJugador, $nomEntrenador];
            
            if ($resP['cel_jugador']) enviarWhatsApp($resP['cel_jugador'], 'reserva_cancelada', 'es_CL', $vars); 
            if ($resP['cel_entrenador']) enviarWhatsApp($resP['cel_entrenador'], 'reserva_cancelada', 'es_CL', $vars);

            // 2. EMAIL
            $subject = "🚫 Cancelación de Entrenamiento Grupal - " . $resP['pack_nombre'];
            
            // Player Body
            $bodyPlayer = "
            <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                <h2 style='color: #d32f2f;'>🚫 Inscripción Grupal Cancelada</h2>
                <p>Hola <strong>$nomJugador</strong>,</p>
                <p>Has cancelado tu inscripción al entrenamiento grupal: <strong>{$resP['pack_nombre']}</strong>.</p>
                <div style='background: #f4f4f4; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                    <p style='margin: 5px 0;'><strong>Día Horario:</strong> $diaNombre</p>
                    <p style='margin: 5px 0;'><strong>Hora de Inicio:</strong> $horaFmt</p>
                    <p style='margin: 5px 0;'><strong>Entrenador:</strong> $nomEntrenador</p>
                </div>
                <p>Tu crédito sigue disponible para inscribirte en otro horario.</p>
                <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
                <p style='font-size: 12px; color: #888;'>Padel Manager - Gestión Integral de Padel</p>
            </div>";

            // Coach Body
            $bodyCoach = "
            <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                <h2 style='color: #d32f2f;'>🚫 Baja en Entrenamiento Grupal</h2>
                <p>Hola <strong>$nomEntrenador</strong>,</p>
                <p>El jugador <strong>$nomJugador</strong> se ha dado de baja de tu entrenamiento grupal: <strong>{$resP['pack_nombre']}</strong>.</p>
                <div style='background: #f4f4f4; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                    <p style='margin: 5px 0;'><strong>Horario:</strong> $diaNombre a las $horaFmt</p>
                </div>
                <p>Un cupo se ha liberado automáticamente en este pack.</p>
                <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
                <p style='font-size: 12px; color: #888;'>Padel Manager - Gestión Integral de Padel</p>
            </div>";

            if (!empty($emailJugador)) enviarCorreoSMTP($emailJugador, $subject, $bodyPlayer);
            if (!empty($emailEntrenador)) enviarCorreoSMTP($emailEntrenador, $subject, $bodyCoach);
        }
    }
    // --- NOTIFICATIONS END ---

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}

$conn->close();
?>
