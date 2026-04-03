<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Version');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}


error_reporting(0);
ini_set('display_errors', 0);

require_once '../db.php';

$jugador_id = $_GET['jugador_id'] ?? null;
$entrenador_id = $_GET['entrenador_id'] ?? null;

if (!$jugador_id) {
    echo json_encode([]);
    exit;
}

try {
    if (!$pdo) {
        throw new Exception("PDO not initialized");
    }

    // 1. Find Mesh
    $stmt = $pdo->prepare("SELECT malla_id FROM alumno_malla WHERE jugador_id = ? AND estado = 'activo' LIMIT 1");
    $stmt->execute([$jugador_id]);
    $malla = $stmt->fetch(PDO::FETCH_COLUMN);

    if (!$malla) {
        $stmt = $pdo->query("SELECT id FROM mallas ORDER BY id DESC LIMIT 1");
        $malla = $stmt->fetch(PDO::FETCH_COLUMN);
    }

    if (!$malla) {
        echo json_encode([]);
        exit;
    }

    // 2. Fetch Sessions
    $query = "SELECT 
                cm.id as clase_malla_id, 
                cm.titulo, 
                cm.objetivo, 
                cm.orden,
                r.id as reserva_id,
                r.fecha as reserva_fecha,
                r.hora_inicio as reserva_hora,
                aa.estado_asistencia
              FROM clases_malla cm
              LEFT JOIN (
                  SELECT rx.id, rx.fecha, rx.hora_inicio, rx.clase_id
                  FROM reservas rx
                  JOIN reserva_jugadores rjx ON rx.id = rjx.reserva_id
                  WHERE rjx.jugador_id = ?
              ) r ON cm.id = r.clase_id
              LEFT JOIN alumno_asistencia aa ON r.id = aa.reserva_id AND aa.jugador_id = ?
              WHERE cm.malla_id = ?
              ORDER BY cm.orden ASC
              LIMIT 4";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$jugador_id, $jugador_id, $malla]);
    $clases = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $historial = [];
    foreach ($clases as $row) {
        $estado = "pendiente";
        if (!empty($row['estado_asistencia'])) {
            $estado = $row['estado_asistencia'];
        } else if (!empty($row['reserva_fecha'])) {
            $estado = "proxima";
        }

        $historial[] = [
            "id" => $row['clase_malla_id'],
            "titulo" => $row['titulo'],
            "objetivo" => $row['objetivo'] ?: "Sin definir",
            "lista_objetivos" => json_decode($row['objetivo'] ?? '[]', true) ?: [$row['objetivo']],
            "fecha" => $row['reserva_fecha'] ?: null,
            "hora" => $row['reserva_hora'] ?: null,
            "estado_asistencia" => $estado,
            "reserva_id" => $row['reserva_id'] ?: null,
            "clase_malla_id" => $row['clase_malla_id']
        ];
    }

    echo json_encode($historial);

} catch (Throwable $e) {
    // Return empty array instead of 500
    echo json_encode([]);
}
