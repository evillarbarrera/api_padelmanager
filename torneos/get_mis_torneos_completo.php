<?php
ob_start();
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(200);
    exit;
}

ini_set('display_errors', 0);
error_reporting(0);

require_once "../db.php";
ob_end_clean();

// Disable exception throwing for mysqli
mysqli_report(MYSQLI_REPORT_OFF);

$user_id = (int)($_GET['user_id'] ?? 0);

if (!$user_id) {
    echo json_encode([]);
    exit;
}

$result = [];

// 1. AMERICANO TOURNAMENTS
try {
    $sqlAm = "SELECT t.id, t.nombre, t.fecha, t.hora_inicio, t.estado,
                     c.nombre as club_nombre,
                     tp.nombre_pareja, tp.id as inscripcion_id
              FROM torneo_participantes tp
              JOIN torneos_americanos t ON tp.torneo_id = t.id
              JOIN clubes c ON t.club_id = c.id
              WHERE (tp.jugador_id = $user_id OR tp.jugador2_id = $user_id)
              ORDER BY t.fecha DESC";

    $resAm = $conn->query($sqlAm);
    if ($resAm) {
        while ($row = $resAm->fetch_assoc()) {
            $row['tipo_torneo'] = 'americano';
            $row['partidos'] = [];

            // Get matches using tp.* to avoid unknown column errors
            $tid = (int)$row['id'];
            try {
                $sqlM = "SELECT tp.*,
                                COALESCE(u1.nombre, 'J1') as jugador1_nombre,
                                COALESCE(u2.nombre, 'J2') as jugador2_nombre,
                                COALESCE(u3.nombre, 'J3') as jugador3_nombre,
                                COALESCE(u4.nombre, 'J4') as jugador4_nombre
                         FROM torneo_partidos tp
                         LEFT JOIN usuarios u1 ON tp.jugador1_id = u1.id
                         LEFT JOIN usuarios u2 ON tp.jugador2_id = u2.id
                         LEFT JOIN usuarios u3 ON tp.jugador3_id = u3.id
                         LEFT JOIN usuarios u4 ON tp.jugador4_id = u4.id
                         WHERE tp.torneo_id = $tid
                         ORDER BY tp.ronda ASC, tp.id ASC";
                $resM = $conn->query($sqlM);
                if ($resM) {
                    while ($m = $resM->fetch_assoc()) {
                        // Normalize result fields
                        $m['resultado_t1'] = $m['puntos_t1'] ?? $m['resultado_t1'] ?? null;
                        $m['resultado_t2'] = $m['puntos_t2'] ?? $m['resultado_t2'] ?? null;
                        $m['es_mi_partido'] = (
                            ($m['jugador1_id'] ?? 0) == $user_id || 
                            ($m['jugador2_id'] ?? 0) == $user_id || 
                            ($m['jugador3_id'] ?? 0) == $user_id || 
                            ($m['jugador4_id'] ?? 0) == $user_id
                        );
                        $row['partidos'][] = $m;
                    }
                }
            } catch (Exception $e) {
                // Skip matches on error
            }
            $result[] = $row;
        }
    }
} catch (Exception $e) {
    // Skip americanos on error
}

// 2. V2 TOURNAMENTS
try {
    $check = $conn->query("SHOW TABLES LIKE 'torneo_inscripciones'");
    if ($check && $check->num_rows > 0) {
        $sqlV2 = "SELECT DISTINCT t.id, t.nombre, t.fecha_inicio as fecha, t.fecha_fin, t.estado,
                         c.nombre as club_nombre,
                         tp.nombre_pareja, tp.id as pareja_id
                  FROM torneo_inscripciones ti
                  JOIN torneo_parejas tp ON ti.pareja_id = tp.id
                  JOIN torneo_categorias tc ON ti.categoria_id = tc.id
                  JOIN torneos_v2 t ON tc.torneo_id = t.id
                  JOIN clubes c ON t.club_id = c.id
                  WHERE (tp.jugador1_id = $user_id OR tp.jugador2_id = $user_id)
                  ORDER BY t.fecha_inicio DESC";

        $resV2 = $conn->query($sqlV2);
        if ($resV2) {
            while ($row = $resV2->fetch_assoc()) {
                $row['tipo_torneo'] = 'oficial';
                $row['partidos'] = [];

                $tid = (int)$row['id'];
                $pid = (int)$row['pareja_id'];

                try {
                    $sqlM = "SELECT p.*,
                                    p1.nombre_pareja as pareja1_nombre, 
                                    p2.nombre_pareja as pareja2_nombre,
                                    cat.nombre as categoria_nombre
                             FROM torneo_partidos_v2 p
                             JOIN torneo_parejas p1 ON p.pareja1_id = p1.id
                             JOIN torneo_parejas p2 ON p.pareja2_id = p2.id
                             JOIN torneo_categorias cat ON p.categoria_id = cat.id
                             WHERE cat.torneo_id = $tid
                             AND (p.pareja1_id = $pid OR p.pareja2_id = $pid)
                             ORDER BY p.id ASC";
                    $resM = $conn->query($sqlM);
                    if ($resM) {
                        while ($m = $resM->fetch_assoc()) {
                            $m['es_mi_partido'] = true;
                            $ganador = $m['ganador_id'] ?? null;
                            if ($ganador) {
                                $m['gane'] = ($ganador == $pid);
                                $m['resultado_t1'] = ($ganador == ($m['pareja1_id'] ?? 0)) ? 1 : 0;
                                $m['resultado_t2'] = ($ganador == ($m['pareja2_id'] ?? 0)) ? 1 : 0;
                            } else {
                                $m['resultado_t1'] = null;
                                $m['resultado_t2'] = null;
                            }
                            $row['partidos'][] = $m;
                        }
                    }
                } catch (Exception $e) {
                    // Skip matches on error
                }
                $result[] = $row;
            }
        }
    }
} catch (Exception $e) {
    // Skip V2 on error
}

echo json_encode($result);
$conn->close();
?>
