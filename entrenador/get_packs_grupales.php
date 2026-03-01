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

$entrenador_id = $_GET['entrenador_id'] ?? null;

if (!$entrenador_id) {
    http_response_code(400);
    echo json_encode(["error" => "entrenador_id es obligatorio"]);
    exit;
}

// Obtener todos los packs grupales del entrenador con detalles completos
$sql = "
SELECT 
    p.id AS pack_id,
    p.nombre AS pack_nombre,
    p.descripcion,
    p.dia_semana,
    p.hora_inicio,
    p.capacidad_minima,
    p.capacidad_maxima,
    COUNT(DISTINCT ig.id) AS cupos_ocupados,
    p.estado_grupo,
    p.categoria,
    p.precio,
    CASE 
        WHEN p.duracion_sesion_min > 0 THEN p.duracion_sesion_min
        WHEN COUNT(DISTINCT ig.id) >= 5 THEN 120
        ELSE 60
    END AS duracion_calculada,
    (p.capacidad_maxima - COUNT(DISTINCT ig.id)) AS cupos_disponibles,
    p.created_at,
    COUNT(DISTINCT ig.id) AS total_inscritos
FROM packs p
LEFT JOIN inscripciones_grupales ig ON ig.pack_id = p.id AND ig.estado = 'activo'
WHERE p.entrenador_id = ?
  AND p.tipo = 'grupal'
  AND p.activo = 1
GROUP BY p.id
ORDER BY p.dia_semana ASC, p.hora_inicio ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $entrenador_id);
$stmt->execute();
$result = $stmt->get_result();

$packs = [];
while ($row = $result->fetch_assoc()) {
    // Obtener lista detallada de inscritos
    $sql_inscritos = "
    SELECT 
        ig.id AS inscripcion_id,
        u.id AS jugador_id,
        u.nombre,
        u.usuario as email,
        ig.fecha_inscripcion,
        ig.estado
    FROM inscripciones_grupales ig
    JOIN usuarios u ON u.id = ig.jugador_id
    WHERE ig.pack_id = ? AND ig.estado = 'activo'
    ORDER BY ig.fecha_inscripcion ASC
    ";
    
    $stmt_inscritos = $conn->prepare($sql_inscritos);
    $stmt_inscritos->bind_param("i", $row['pack_id']);
    $stmt_inscritos->execute();
    $result_inscritos = $stmt_inscritos->get_result();
    
    $inscritos = [];
    while ($inscrito = $result_inscritos->fetch_assoc()) {
        $inscritos[] = $inscrito;
    }
    
    $row['inscritos'] = $inscritos;
    $packs[] = $row;
}

echo json_encode($packs);
$conn->close();
