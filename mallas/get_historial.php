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

$jugador_id = intval($_GET['jugador_id'] ?? 0);
$entrenador_id = intval($_GET['entrenador_id'] ?? 0);
$pack_id = intval($_GET['pack_id'] ?? 0); // NEW: Filter by specific pack

if (!$jugador_id) {
    echo json_encode([]);
    exit;
}

try {
    // 1. Find Mesh in follow-up table (alumno_malla_seguimiento) for THIS trainer AND PACK
    $sqlMalla = "SELECT malla_id FROM alumno_malla_seguimiento WHERE jugador_id = ? AND estado = 'activo'";
    $params = [$jugador_id];
    $types = "i";

    if ($pack_id) {
        $sqlMalla .= " AND (pack_id = ? OR pack_id = 0)";
        $params[] = $pack_id;
        $types .= "i";
    } else if ($entrenador_id) {
        $sqlMalla .= " AND entrenador_id = ?";
        $params[] = $entrenador_id;
        $types .= "i";
    }
    $sqlMalla .= " LIMIT 1";

    $stmtM = $conn->prepare($sqlMalla);
    $stmtM->bind_param($types, ...$params);
    $stmtM->execute();
    $resM = $stmtM->get_result();
    $malla = 0;
    if ($rowM = $resM->fetch_assoc()) {
        $malla = $rowM['malla_id'];
    }

    if (!$malla) {
        // Fallback to latest global mesh if none active
        $resFB = $conn->query("SELECT id FROM mallas ORDER BY id DESC LIMIT 1");
        if ($rowFB = $resFB->fetch_assoc()) {
            $malla = $rowFB['id'];
        }
    }

    if (!$malla) {
        echo json_encode([]);
        exit;
    }

    // 2. Fetch Sessions for EXACT mesh AND PACK
    $query = "SELECT 
                cm.id as clase_malla_id, 
                cm.titulo, 
                cm.objetivo, 
                cm.orden,
                cm.calentamiento,
                cm.parte_tecnica,
                cm.drills,
                cm.juego,
                cm.recursos,
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
                    AND (rx.pack_id = ? OR rx.pack_id = 0 OR rx.pack_id IS NULL)
                    AND rx.estado != 'cancelado' 
                    AND rx.clase_id > 0
              ) r ON cm.id = r.clase_id
              LEFT JOIN alumno_asistencia aa ON r.id = aa.reserva_id AND aa.jugador_id = ?
              WHERE cm.malla_id = ?
              ORDER BY cm.orden ASC
              LIMIT 15";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iiii", $jugador_id, $pack_id, $jugador_id, $malla);
    $stmt->execute();
    $result = $stmt->get_result();

    $historial = [];
    while ($row = $result->fetch_assoc()) {
        $estado = "pendiente";
        $now = new DateTime();
        
        if (!empty($row['estado_asistencia'])) {
            $estado = $row['estado_asistencia'];
        } else if (!empty($row['reserva_fecha'])) {
            // Check if reservation is in the past
            $reserva_dt = new DateTime($row['reserva_fecha'] . ' ' . ($row['reserva_hora'] ?: '00:00:00'));
            if ($reserva_dt < $now) {
                $estado = "completada"; // Past without explicit attendance = assumed completed
            } else {
                $estado = "proxima";
            }
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
            "clase_malla_id" => $row['clase_malla_id'],
            "calentamiento" => $row['calentamiento'] ?: "",
            "parte_tecnica" => $row['parte_tecnica'] ?: "",
            "drills" => $row['drills'] ?: "",
            "juego" => $row['juego'] ?: "",
            "recursos" => $row['recursos'] ?: ""
        ];
    }

    echo json_encode($historial);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
