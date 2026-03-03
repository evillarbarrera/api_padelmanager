<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
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
if (!$data) {
    http_response_code(400);
    echo json_encode(["error" => "Datos inválidos"]);
    exit;
}

$pack_id = $data['pack_id'] ?? 0;
$jugador_id = $data['jugador_id'] ?? 0;

if (!$pack_id || !$jugador_id) {
    http_response_code(400);
    echo json_encode(["error" => "pack_id y jugador_id son requeridos"]);
    exit;
}

require_once "../db.php";

$conn->begin_transaction();

try {
    // 1. Obtener información del pack
    $sql_pack = "SELECT * FROM packs WHERE id = ? AND tipo = 'grupal' AND activo = 1";
    $stmt = $conn->prepare($sql_pack);
    $stmt->bind_param("i", $pack_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $pack = $result->fetch_assoc();

    if (!$pack) {
        throw new Exception("Pack grupal no encontrado");
    }

    // 2. Verificar cupos disponibles
    if ($pack['cupos_ocupados'] >= $pack['capacidad_maxima']) {
        throw new Exception("No hay cupos disponibles");
    }

    // 3. Verificar que el jugador no esté inscrito
    $sql_check = "SELECT id FROM inscripciones_grupales WHERE pack_id = ? AND jugador_id = ? AND estado = 'activo'";
    $stmt = $conn->prepare($sql_check);
    $stmt->bind_param("ii", $pack_id, $jugador_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        throw new Exception("El jugador ya está inscrito en este pack");
    }

    // 4. Insertar inscripción
    $sql_insert = "INSERT INTO inscripciones_grupales (pack_id, jugador_id, fecha_inscripcion, estado) VALUES (?, ?, NOW(), 'activo')";
    $stmt = $conn->prepare($sql_insert);
    $stmt->bind_param("ii", $pack_id, $jugador_id);
    $stmt->execute();
    $inscripcion_id = $conn->insert_id;

    // 5. Incrementar cupos ocupados
    $new_cupos = $pack['cupos_ocupados'] + 1;
    $sql_update_cupos = "UPDATE packs SET cupos_ocupados = ? WHERE id = ?";
    $stmt = $conn->prepare($sql_update_cupos);
    $stmt->bind_param("ii", $new_cupos, $pack_id);
    $stmt->execute();

    // 6. Si se alcanzó el mínimo, activar el pack y bloquear disponibilidad
    if ($new_cupos >= $pack['capacidad_minima'] && $pack['estado_grupo'] !== 'activo') {
        // Calcular duración: Priorizar configuración del pack, sino usar lógica dinámica (<5: 60min, >=5: 120min)
        $duracion = ($pack['duracion_sesion_min'] > 0) 
            ? $pack['duracion_sesion_min'] 
            : (($new_cupos >= 5) ? 120 : 60);

        // Cambiar estado a activo
        $sql_update_estado = "UPDATE packs SET estado_grupo = 'activo' WHERE id = ?";
        $stmt = $conn->prepare($sql_update_estado);
        $stmt->bind_param("i", $pack_id);
        $stmt->execute();

        // Bloquear horario en disponibilidad
        $sql_bloque = "INSERT INTO bloques_grupo (pack_id, entrenador_id, dia_semana, hora_inicio, duracion_minutos) 
                       VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql_bloque);
        $stmt->bind_param("iiiis", 
            $pack_id, 
            $pack['entrenador_id'], 
            $pack['dia_semana'], 
            $pack['hora_inicio'], 
            $duracion
        );
        $stmt->execute();
    }

    $conn->commit();

    echo json_encode([
        "success" => true,
        "inscripcion_id" => $inscripcion_id,
        "cupos_ocupados" => $new_cupos,
        "estado_grupo" => $pack['estado_grupo'],
        "message" => "Inscripción realizada exitosamente"
    ]);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(["error" => $e->getMessage()]);
}
