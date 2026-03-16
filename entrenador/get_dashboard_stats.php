<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

$data = [
    "total_alumnos" => 0,
    "clases_mes" => 0,
    "clases_grupales_mes" => 0,
    "clases_hoy" => 0
];

// 1. Cantidad de alumnos total (únicos con packs o reservas de este entrenador)
$sql_alumnos = "
SELECT COUNT(DISTINCT jugador_id) as total FROM (
    SELECT pj.jugador_id 
    FROM pack_jugadores pj
    JOIN packs p ON p.id = pj.pack_id
    WHERE p.entrenador_id = ?
    
    UNION
    
    SELECT rj.jugador_id
    FROM reserva_jugadores rj
    JOIN reservas r ON r.id = rj.reserva_id
    WHERE r.entrenador_id = ? AND r.estado != 'cancelado'
) as alumnos_unicos
";

$stmt = $conn->prepare($sql_alumnos);
$stmt->bind_param("ii", $entrenador_id, $entrenador_id);
$stmt->execute();
$data["total_alumnos"] = (int)$stmt->get_result()->fetch_assoc()['total'];

// 2. Clases del mes (Asegurando contar por sesión única, no por alumno)
$first_day = date('Y-m-01');
$last_day = date('Y-m-t');

$sql_reservas = "
SELECT fecha, hora_inicio, tipo, pack_id
FROM reservas
WHERE entrenador_id = ? 
  AND estado != 'cancelado'
  AND fecha BETWEEN ? AND ?
GROUP BY fecha, hora_inicio
";
$stmt = $conn->prepare($sql_reservas);
$stmt->bind_param("iss", $entrenador_id, $first_day, $last_day);
$stmt->execute();
$res_reservas = $stmt->get_result();

$clases_unicas = [];
$grupales_unicas = 0;

while($r = $res_reservas->fetch_assoc()) {
    $key = $r['fecha'] . '_' . $r['hora_inicio'];
    $clases_unicas[$key] = true;
    if ($r['tipo'] === 'grupal' || $r['tipo'] === 'pack_grupal') {
        $grupales_unicas++;
    }
}

$data["clases_mes"] = count($clases_unicas);
$data["clases_grupales_mes"] = $grupales_unicas;

// 3. Clases de hoy (Sesiones únicas)
$hoy = date('Y-m-d');
$sql_hoy = "
SELECT COUNT(DISTINCT hora_inicio) as total
FROM reservas
WHERE entrenador_id = ? 
  AND estado != 'cancelado'
  AND fecha = ?
";
$stmt = $conn->prepare($sql_hoy);
$stmt->bind_param("is", $entrenador_id, $hoy);
$stmt->execute();
$res_hoy_res = $stmt->get_result()->fetch_assoc();
$total_hoy = (int)$res_hoy_res['total'];
$data["clases_hoy"] = $total_hoy;

// 4. Clases Pendientes (Créditos de alumnos sin agendar + Reservas futuras)
// A. Créditos disponibles en packs (Sin agendar)
$sql_creditos = "
    SELECT SUM(t.creditos_sin_agendar) as total_creditos
    FROM (
        SELECT 
            (p.sesiones_totales - (
                SELECT COUNT(*) 
                FROM reserva_jugadores rj2 
                JOIN reservas r2 ON rj2.reserva_id = r2.id 
                WHERE rj2.jugador_id = pj.jugador_id 
                  AND r2.pack_id = p.id 
                  AND r2.estado != 'cancelado'
            )) as creditos_sin_agendar
        FROM pack_jugadores pj
        JOIN packs p ON p.id = pj.pack_id
        WHERE p.entrenador_id = ?
          AND p.tipo NOT IN ('grupal', 'pack_grupal')
    ) t
";
$stmt = $conn->prepare($sql_creditos);
$stmt->bind_param("i", $entrenador_id);
$stmt->execute();
$creditos_res = (int)$stmt->get_result()->fetch_assoc()['total_creditos'];

// B. Reservas Futuras (Individuales y Grupales - Sesiones Únicas)
$sql_futuras = "
    SELECT COUNT(DISTINCT CASE WHEN r.tipo = 'grupal' THEN CONCAT(r.fecha, r.hora_inicio, r.pack_id) ELSE r.id END) as total_futuras
    FROM reservas r
    WHERE r.entrenador_id = ? 
      AND r.estado != 'cancelado'
      AND (r.fecha > CURDATE() OR (r.fecha = CURDATE() AND r.hora_fin > CURTIME()))
";
$stmt = $conn->prepare($sql_futuras);
$stmt->bind_param("i", $entrenador_id);
$stmt->execute();
$futuras_res = (int)$stmt->get_result()->fetch_assoc()['total_futuras'];

$data["clases_pendientes"] = max(0, $creditos_res) + $futuras_res;
$data["clases_sin_agendar"] = max(0, $creditos_res);
$data["reservas_futuras"] = $futuras_res;

// 5. Promo Check (3 months free)
$sql_promo = "SELECT created_at FROM usuarios WHERE id = ?";
$stmt_promo = $conn->prepare($sql_promo);
$stmt_promo->bind_param("i", $entrenador_id);
$stmt_promo->execute();
$res_promo = $stmt_promo->get_result()->fetch_assoc();

$data["promo_activa"] = false;
$data["promo_dias_restantes"] = 0;

if ($res_promo && !empty($res_promo['created_at'])) {
    $created = new DateTime($res_promo['created_at']);
    $now = new DateTime();
    
    // Calculate 3 months later date
    $threeMonthsLater = clone $created;
    $threeMonthsLater->modify('+3 months');
    
    if ($now < $threeMonthsLater) {
        $data["promo_activa"] = true;
        $interval = $now->diff($threeMonthsLater);
        $data["promo_dias_restantes"] = (int)$interval->format("%r%a");
    }
}

echo json_encode($data);
$conn->close();
