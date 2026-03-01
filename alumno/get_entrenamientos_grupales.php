<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
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

$jugador_id = $_GET['jugador_id'] ?? null;

if (!$jugador_id) {
    http_response_code(400);
    echo json_encode(["error" => "jugador_id es obligatorio"]);
    exit;
}

// Obtener todos los entrenamientos grupales del jugador
$sql = "
SELECT 
    ig.id AS inscripcion_id,
    p.id AS pack_id,
    p.nombre AS pack_nombre,
    p.descripcion,
    p.categoria,
    p.dia_semana,
    p.hora_inicio,
    p.capacidad_minima,
    p.capacidad_maxima,
    p.cupos_ocupados,
    p.estado_grupo,
    CASE 
        WHEN p.duracion_sesion_min > 0 THEN p.duracion_sesion_min
        WHEN p.cupos_ocupados >= 5 THEN 120
        ELSE 60
    END AS duracion_calculada,
    (p.capacidad_maxima - p.cupos_ocupados) AS cupos_disponibles,
    u_e.id AS entrenador_id,
    u_e.nombre AS entrenador_nombre,
    ig.fecha_inscripcion,
    ig.estado
FROM inscripciones_grupales ig
INNER JOIN packs p ON p.id = ig.pack_id
INNER JOIN usuarios u_e ON u_e.id = p.entrenador_id
WHERE ig.jugador_id = ?
  AND ig.estado = 'activo'
  AND p.activo = 1
  AND p.estado_grupo IN ('pendiente', 'activo')
ORDER BY p.dia_semana ASC, p.hora_inicio ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $jugador_id);
$stmt->execute();
$result = $stmt->get_result();

$entrenamientos = [];
while ($row = $result->fetch_assoc()) {
    // Obtener lista de otros inscritos (para mostrar compañeros)
    $sql_otros = "
    SELECT 
        u.id AS jugador_id,
        u.nombre,
        u.usuario as email
    FROM inscripciones_grupales ig
    JOIN usuarios u ON u.id = ig.jugador_id
    WHERE ig.pack_id = ? AND ig.estado = 'activo' AND ig.jugador_id != ?
    ORDER BY ig.fecha_inscripcion ASC
    ";
    
    $stmt_otros = $conn->prepare($sql_otros);
    $stmt_otros->bind_param("ii", $row['pack_id'], $jugador_id);
    $stmt_otros->execute();
    $result_otros = $stmt_otros->get_result();
    
    $otros_inscritos = [];
    while ($otro = $result_otros->fetch_assoc()) {
        $otros_inscritos[] = $otro;
    }
    
    $row['otros_inscritos'] = $otros_inscritos;
    $entrenamientos[] = $row;
}

echo json_encode($entrenamientos);
$conn->close();
