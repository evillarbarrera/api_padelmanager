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

// Authorization
$headers = getallheaders();
$auth = $headers['Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

if ($auth !== 'Bearer ' . base64_encode("1|padel_academy")) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}
require_once "../db.php";

$entrenador_id = $_GET['entrenador_id'] ?? null;
$club_id = $_GET['club_id'] ?? 1;

if (!$entrenador_id) {
    http_response_code(400);
    echo json_encode(["error" => "entrenador_id es obligatorio"]);
    exit;
}

$pack_id = $_GET['pack_id'] ?? null;
$rango_inicio = null;
$rango_fin = null;

// Handle Pack Time Constraints
if ($pack_id) {
    if ($stmtPack = $conn->prepare("SELECT rango_horario_inicio, rango_horario_fin FROM packs WHERE id = ?")) {
        $stmtPack->bind_param("i", $pack_id);
        $stmtPack->execute();
        $resPack = $stmtPack->get_result()->fetch_assoc();
        if ($resPack) {
            $rango_inicio = $resPack['rango_horario_inicio'];
            $rango_fin = $resPack['rango_horario_fin'];
        }
        $stmtPack->close();
    }
}

/* ============================
   SQL
============================ */
$sql = "
SELECT 
  d.id as slot_id,
  d.fecha_inicio,
  d.fecha_fin,
  d.club_id,
  c.nombre as club_nombre,
  c.direccion as club_direccion,
  IFNULL(SUM(CASE WHEN r.tipo = 'individual' THEN 1 ELSE 0 END), 0) as count_individual,
  IFNULL(COUNT(r.id), 0) as inscritos_count,
  IFNULL(MAX(p.capacidad_maxima), 6) as cantidad_personas
FROM disponibilidad_profesor d
LEFT JOIN clubes c ON c.id = d.club_id
LEFT JOIN reservas r
  ON r.entrenador_id = d.profesor_id
 AND r.fecha = DATE(d.fecha_inicio)
 AND r.hora_inicio < TIME(d.fecha_fin)
 AND r.hora_fin > TIME(d.fecha_inicio)
 AND r.estado = 'reservado'
LEFT JOIN packs p ON p.id = r.pack_id
WHERE d.profesor_id = ?
  AND d.activo = 1
  AND DATE(d.fecha_inicio) >= CURDATE()
GROUP BY d.id, d.fecha_inicio, d.fecha_fin, d.club_id, c.nombre, c.direccion
ORDER BY d.fecha_inicio
";

/* ============================
   PREPARE
============================ */
$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => "Error preparando query", "mysql" => $conn->error]);
    exit;
}

/* ============================
   BIND + EXEC
============================ */
$stmt->bind_param("i", $entrenador_id);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(["error" => "Error ejecutando query", "mysql" => $stmt->error]);
    exit;
}

$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    // Determine if occupied based on capacity
    $row['ocupado'] = 0;
    if ($row['count_individual'] > 0) {
        $row['ocupado'] = 1;
    } else {
        $capacity = (int)($row['cantidad_personas'] ?: 6);
        if ($row['inscritos_count'] >= $capacity) {
            $row['ocupado'] = 1;
        }
    }

    // Filter by Pack Time Range if applicable
    if ($rango_inicio && $rango_fin) {
        $slotTime = date('H:i:s', strtotime($row['fecha_inicio']));
        if ($slotTime < $rango_inicio || $slotTime >= $rango_fin) {
             continue; 
        }
    }
    $data[] = $row;
}

echo json_encode($data);

$stmt->close();
$conn->close();
