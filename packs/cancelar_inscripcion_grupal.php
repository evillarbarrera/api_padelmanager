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

$inscripcion_id = $data['inscripcion_id'] ?? 0;

if (!$inscripcion_id) {
    http_response_code(400);
    echo json_encode(["error" => "inscripcion_id es requerido"]);
    exit;
}

require_once "../db.php";

$conn->begin_transaction();

try {
    // 1. Obtener información de la inscripción
    $sql_inscripcion = "SELECT ig.*, p.* FROM inscripciones_grupales ig
                        JOIN packs p ON p.id = ig.pack_id
                        WHERE ig.id = ? AND ig.estado = 'activo'";
    $stmt = $conn->prepare($sql_inscripcion);
    $stmt->bind_param("i", $inscripcion_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $inscripcion = $result->fetch_assoc();

    if (!$inscripcion) {
        throw new Exception("Inscripción no encontrada");
    }

    $pack_id = $inscripcion['pack_id'];

    // 2. Marcar inscripción como cancelada
    $sql_cancel = "UPDATE inscripciones_grupales SET estado = 'cancelado' WHERE id = ?";
    $stmt = $conn->prepare($sql_cancel);
    $stmt->bind_param("i", $inscripcion_id);
    $stmt->execute();

    // 3. Decrementar cupos ocupados
    $new_cupos = $inscripcion['cupos_ocupados'] - 1;
    $sql_update_cupos = "UPDATE packs SET cupos_ocupados = ? WHERE id = ?";
    $stmt = $conn->prepare($sql_update_cupos);
    $stmt->bind_param("ii", $new_cupos, $pack_id);
    $stmt->execute();

    // 4. Si baja de mínimo, cambiar a pendiente y liberar horario
    if ($new_cupos < $inscripcion['capacidad_minima'] && $inscripcion['estado_grupo'] === 'activo') {
        // Cambiar estado a pendiente
        $sql_update_estado = "UPDATE packs SET estado_grupo = 'pendiente' WHERE id = ?";
        $stmt = $conn->prepare($sql_update_estado);
        $stmt->bind_param("i", $pack_id);
        $stmt->execute();

        // Liberar bloque de disponibilidad
        $sql_delete_bloque = "DELETE FROM bloques_grupo WHERE pack_id = ?";
        $stmt = $conn->prepare($sql_delete_bloque);
        $stmt->bind_param("i", $pack_id);
        $stmt->execute();
    }

    $conn->commit();

    echo json_encode([
        "success" => true,
        "cupos_ocupados" => $new_cupos,
        "estado_grupo" => $new_cupos < $inscripcion['capacidad_minima'] ? 'pendiente' : 'activo',
        "message" => "Inscripción cancelada exitosamente"
    ]);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(["error" => $e->getMessage()]);
}
