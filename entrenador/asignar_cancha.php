<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../auth/auth_helper.php";
$entrenador_id_auth = validateToken();
if (!$entrenador_id_auth) {
    sendUnauthorized();
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    http_response_code(400);
    echo json_encode(["error" => "Datos inválidos"]);
    exit;
}

$reserva_id = intval($data['reserva_id'] ?? 0);
$cancha_id = isset($data['cancha_id']) ? intval($data['cancha_id']) : null;

if (!$reserva_id) {
    http_response_code(400);
    echo json_encode(["error" => "reserva_id es obligatorio"]);
    exit;
}

// 1. Obtener datos de la reserva
$sqlRes = "SELECT r.id, r.entrenador_id, r.fecha, r.hora_inicio, r.hora_fin, r.club_id, r.cancha_id as cancha_actual,
                  p.club_id as pack_club_id, p.nombre as pack_nombre
           FROM reservas r
           LEFT JOIN packs p ON p.id = r.pack_id
           WHERE r.id = ? LIMIT 1";
$stmtRes = $conn->prepare($sqlRes);
$stmtRes->bind_param("i", $reserva_id);
$stmtRes->execute();
$reserva = $stmtRes->get_result()->fetch_assoc();

if (!$reserva) {
    http_response_code(404);
    echo json_encode(["error" => "Reserva no encontrada"]);
    exit;
}

$entrenador_id = intval($reserva['entrenador_id']);
$fecha = $reserva['fecha'];
$hora_inicio = $reserva['hora_inicio'];
$hora_fin = $reserva['hora_fin'];
$club_id = intval($reserva['club_id'] ?: $reserva['pack_club_id']);

$cancha_nombre = '';

if ($cancha_id && $cancha_id > 0) {
    // 2. Validar que la cancha exista
    $stmtC = $conn->prepare("SELECT id, nombre, club_id FROM canchas WHERE id = ? LIMIT 1");
    $stmtC->bind_param("i", $cancha_id);
    $stmtC->execute();
    $canchaRow = $stmtC->get_result()->fetch_assoc();

    if (!$canchaRow) {
        http_response_code(404);
        echo json_encode(["error" => "La cancha especificada no existe"]);
        exit;
    }

    $cancha_nombre = $canchaRow['nombre'];
    if (!$club_id) {
        $club_id = intval($canchaRow['club_id']);
    }

    // 3. Validar disponibilidad de la cancha (que no tenga conflicto en reservas_cancha)
    $sqlConflict = "SELECT id FROM reservas_cancha 
                    WHERE cancha_id = ? AND fecha = ?
                      AND LOWER(TRIM(estado)) NOT IN ('cancelada', 'cancelado', 'anulada', 'anulado', 'rechazada', 'eliminada')
                      AND (hora_inicio < ? AND hora_fin > ?)
                      AND (reserva_entrenador_id IS NULL OR reserva_entrenador_id != ?)
                    LIMIT 1";
    $stmtConflict = $conn->prepare($sqlConflict);
    $stmtConflict->bind_param("isssi", $cancha_id, $fecha, $hora_fin, $hora_inicio, $reserva_id);
    $stmtConflict->execute();
    if ($stmtConflict->get_result()->num_rows > 0) {
        http_response_code(400);
        echo json_encode(["error" => "La cancha '$cancha_nombre' ya está ocupada en ese horario"]);
        exit;
    }

    // 4. Actualizar reservas
    $stmtUpd = $conn->prepare("UPDATE reservas SET cancha_id = ?, club_id = COALESCE(NULLIF(club_id, 0), ?) WHERE id = ?");
    $stmtUpd->bind_param("iii", $cancha_id, $club_id, $reserva_id);
    $stmtUpd->execute();

    // 5. Sincronizar reservas_cancha
    // Obtener nombres para nombre_externo
    $stmtCoach = $conn->prepare("SELECT nombre, apellido FROM usuarios WHERE id = ?");
    $stmtCoach->bind_param("i", $entrenador_id);
    $stmtCoach->execute();
    $coachData = $stmtCoach->get_result()->fetch_assoc();
    $coachNom = trim(($coachData['nombre'] ?? '') . ' ' . ($coachData['apellido'] ?? ''));

    // Alumnos
    $stmtAlumnos = $conn->prepare("SELECT u.nombre, u.apellido FROM reserva_jugadores rj JOIN usuarios u ON u.id = rj.jugador_id WHERE rj.reserva_id = ?");
    $stmtAlumnos->bind_param("i", $reserva_id);
    $stmtAlumnos->execute();
    $resAlum = $stmtAlumnos->get_result();
    $nombresAlum = [];
    while ($alRow = $resAlum->fetch_assoc()) {
        $nombresAlum[] = trim($alRow['nombre'] . ' ' . ($alRow['apellido'] ?? ''));
    }
    $alumTexto = !empty($nombresAlum) ? implode(', ', $nombresAlum) : 'Alumno';
    $nombreExterno = "🎾 Clase: $coachNom ($alumTexto)";

    // Verificar si ya existe registro en reservas_cancha
    $chkRC = $conn->prepare("SELECT id FROM reservas_cancha WHERE reserva_entrenador_id = ? LIMIT 1");
    $chkRC->bind_param("i", $reserva_id);
    $chkRC->execute();
    $resRC = $chkRC->get_result();

    if ($rowRC = $resRC->fetch_assoc()) {
        $rcId = intval($rowRC['id']);
        $updRC = $conn->prepare("UPDATE reservas_cancha SET cancha_id = ?, fecha = ?, hora_inicio = ?, hora_fin = ?, estado = 'Confirmada', nombre_externo = ? WHERE id = ?");
        $updRC->bind_param("issssi", $cancha_id, $fecha, $hora_inicio, $hora_fin, $nombreExterno, $rcId);
        $updRC->execute();
    } else {
        // Insertar nuevo registro vinculado
        $insRC = $conn->prepare("INSERT INTO reservas_cancha 
            (cancha_id, usuario_id, nombre_externo, fecha, hora_inicio, hora_fin, precio, precio_total, pagado, estado, tipo_reserva, reserva_entrenador_id, entrenador_id)
            VALUES (?, ?, ?, ?, ?, ?, 0, 0, 1, 'Confirmada', 'entrenamiento', ?, ?)");
        $insRC->bind_param("iissssii", $cancha_id, $entrenador_id, $nombreExterno, $fecha, $hora_inicio, $hora_fin, $reserva_id, $entrenador_id);
        $insRC->execute();
    }

} else {
    // Desasignar cancha
    $stmtUpd = $conn->prepare("UPDATE reservas SET cancha_id = NULL WHERE id = ?");
    $stmtUpd->bind_param("i", $reserva_id);
    $stmtUpd->execute();

    // Cancelar en reservas_cancha
    $delRC = $conn->prepare("UPDATE reservas_cancha SET estado = 'Cancelada' WHERE reserva_entrenador_id = ?");
    $delRC->bind_param("i", $reserva_id);
    $delRC->execute();
}

echo json_encode([
    "success" => true,
    "reserva_id" => $reserva_id,
    "cancha_id" => $cancha_id,
    "cancha_nombre" => $cancha_nombre,
    "message" => ($cancha_id > 0) ? "Cancha '$cancha_nombre' asignada con éxito" : "Cancha desasignada"
]);
