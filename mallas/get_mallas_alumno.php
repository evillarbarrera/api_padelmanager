<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$jugador_id = $_GET['jugador_id'] ?? 0;
$entrenador_id = $_GET['entrenador_id'] ?? 0;

if (!$jugador_id) {
    http_response_code(400);
    echo json_encode(["error" => "jugador_id es requerido"]);
    exit;
}

// 0. Robust Column Check
$check = $conn->query("SHOW COLUMNS FROM alumno_malla_seguimiento LIKE 'pack_id'");
if ($check && $check->num_rows == 0) {
    $conn->query("ALTER TABLE alumno_malla_seguimiento ADD pack_id INT DEFAULT 0 AFTER entrenador_id");
}

try {
    // Fetch meshes including coach and pack names
    $sql = "
        SELECT 
            ams.id as seguimiento_id,
            ams.entrenador_id,
            u.nombre as entrenador_nombre,
            ams.pack_id,
            COALESCE(p.nombre, 'Sin Pack Específico') as pack_nombre,
            m.id as malla_id,
            m.nombre as malla_nombre,
            m.nivel as malla_nivel,
            ams.estado,
            ams.fecha_inicio
        FROM alumno_malla_seguimiento ams
        JOIN mallas m ON ams.malla_id = m.id
        JOIN usuarios u ON ams.entrenador_id = u.id
        LEFT JOIN packs p ON ams.pack_id = p.id
        WHERE ams.jugador_id = ? AND ams.estado = 'activo'
          AND (p.tipo NOT IN ('grupal', 'pack_grupal', 'clase grupal') OR p.id IS NULL)
    ";

    if ($entrenador_id) {
        $sql .= " AND ams.entrenador_id = $entrenador_id";
    }

    $sql .= " ORDER BY ams.fecha_inicio DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $jugador_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    echo json_encode($data);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
?>
