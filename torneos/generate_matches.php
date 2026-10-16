<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$headers = getallheaders();
require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}

require_once "../db.php";
require_once "match_utils.php"; // Include helper for insertMatch
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$data = json_decode(file_get_contents("php://input"), true);
$torneo_id = $data['torneo_id'] ?? 0;

if (!$torneo_id) {
    http_response_code(400);
    echo json_encode(["error" => "torneo_id es requerido"]);
    exit;
}

// 1. Obtener datos del torneo
$sqlTorneo = "SELECT * FROM torneos_americanos WHERE id = ?";
$stmtT = $conn->prepare($sqlTorneo);
$stmtT->bind_param("i", $torneo_id);
$stmtT->execute();
$torneo = $stmtT->get_result()->fetch_assoc();

if (!$torneo) {
    http_response_code(404);
    echo json_encode(["error" => "Torneo no encontrado"]);
    exit;
}

// 2. Obtener participantes (PAREJAS)
$sqlPart = "SELECT id, jugador_id, jugador2_id, nombre_externo_1, nombre_externo_2 FROM torneo_participantes WHERE torneo_id = ?";
$stmtP = $conn->prepare($sqlPart);
$stmtP->bind_param("i", $torneo_id);
$stmtP->execute();
$resP = $stmtP->get_result();
$pairs = [];
while($row = $resP->fetch_assoc()) {
    $pairs[] = $row;
}

$num_pairs = count($pairs);
$num_canchas = $torneo['num_canchas'];
$pairs_needed = $num_canchas * 2;

if ($num_pairs < 2) {
    http_response_code(400);
    echo json_encode(["error" => "Se necesitan al menos 2 parejas para generar un fixture."]);
    exit;
}

// 3. Generar Fixture por Parejas
// Ensure we always return JSON even on fatal crash
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't print formatted HTML errors

// Catch fatal errors that stop script execution
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(["error" => "FATAL ERROR: " . $error['message'] . " on line " . $error['line']]);
        exit; 
    }
});

try {
    // 1. ROBUST SILENT MIGRATION (Compatibility for older MySQL versions)
    function checkAndAddColumn($conn, $table, $column, $definition) {
        $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if ($res && $res->num_rows == 0) {
            $conn->query("ALTER TABLE `$table` ADD `$column` $definition");
        }
    }

    // torneo_partidos
    checkAndAddColumn($conn, 'torneo_partidos', 'ronda', "INT NULL");
    checkAndAddColumn($conn, 'torneo_partidos', 'num_cancha', "INT NULL");
    checkAndAddColumn($conn, 'torneo_partidos', 'hora_inicio', "TIME NULL");
    checkAndAddColumn($conn, 'torneo_partidos', 'jugador1_id', "INT NULL");
    checkAndAddColumn($conn, 'torneo_partidos', 'jugador2_id', "INT NULL");
    checkAndAddColumn($conn, 'torneo_partidos', 'jugador3_id', "INT NULL");
    checkAndAddColumn($conn, 'torneo_partidos', 'jugador4_id', "INT NULL");
    checkAndAddColumn($conn, 'torneo_partidos', 'nombre_externo_1', "VARCHAR(100) NULL");
    checkAndAddColumn($conn, 'torneo_partidos', 'nombre_externo_2', "VARCHAR(100) NULL");
    checkAndAddColumn($conn, 'torneo_partidos', 'nombre_externo_3', "VARCHAR(100) NULL");
    checkAndAddColumn($conn, 'torneo_partidos', 'nombre_externo_4', "VARCHAR(100) NULL");
    checkAndAddColumn($conn, 'torneo_partidos', 'grupo_id', "VARCHAR(10) NULL");
    checkAndAddColumn($conn, 'torneo_partidos', 'fase', "VARCHAR(50) DEFAULT 'Grupos'");

    // torneo_participantes
    checkAndAddColumn($conn, 'torneo_participantes', 'jugador_id', "INT NULL");
    checkAndAddColumn($conn, 'torneo_participantes', 'jugador2_id', "INT NULL");
    checkAndAddColumn($conn, 'torneo_participantes', 'nombre_pareja', "VARCHAR(255) NULL");
    checkAndAddColumn($conn, 'torneo_participantes', 'nombre_externo_1', "VARCHAR(100) NULL");
    checkAndAddColumn($conn, 'torneo_participantes', 'nombre_externo_2', "VARCHAR(100) NULL");
    checkAndAddColumn($conn, 'torneo_participantes', 'grupo_id', "VARCHAR(10) NULL");

    // reservas_cancha
    checkAndAddColumn($conn, 'reservas_cancha', 'torneo_id', "INT NULL");
    checkAndAddColumn($conn, 'reservas_cancha', 'jugador2_id', "INT NULL");
    checkAndAddColumn($conn, 'reservas_cancha', 'jugador3_id', "INT NULL");
    checkAndAddColumn($conn, 'reservas_cancha', 'jugador4_id', "INT NULL");
    checkAndAddColumn($conn, 'reservas_cancha', 'nombre_externo', "VARCHAR(255) NULL");
    checkAndAddColumn($conn, 'reservas_cancha', 'nombre_externo2', "VARCHAR(255) NULL");
    checkAndAddColumn($conn, 'reservas_cancha', 'nombre_externo3', "VARCHAR(255) NULL");
    checkAndAddColumn($conn, 'reservas_cancha', 'nombre_externo4', "VARCHAR(255) NULL");

    $tipo_torneo = $torneo['tipo_torneo'] ?? 'estandar';
    $conn->query("SET SESSION foreign_key_checks = 0");
    
    // Cleanup previous data
    $conn->query("DELETE FROM torneo_partidos WHERE torneo_id = $torneo_id");
    // Also delete previous reservations for this tournament to avoid mess
    $conn->query("DELETE FROM reservas_cancha WHERE torneo_id = $torneo_id OR (fecha = '" . $torneo['fecha'] . "' AND precio = 0 AND estado = 'Confirmada' AND usuario_id IN (SELECT jugador_id FROM torneo_participantes WHERE torneo_id = $torneo_id))");
    
    // Obtener canchas del club
    $sqlCanchas = "SELECT id FROM canchas WHERE club_id = ? AND activa = 1";
    $stmtC = $conn->prepare($sqlCanchas);
    $stmtC->bind_param("i", $torneo['club_id']);
    $stmtC->execute();
    $resC = $stmtC->get_result();
    $canchas_ids = [];
    while ($rowC = $resC->fetch_assoc()) {
        $canchas_ids[] = $rowC['id'];
    }
    
    if (empty($canchas_ids)) {
        throw new Exception("El club no tiene canchas activas configuradas.");
    }

    $num_canchas_a_usar = max(1, min((int)$num_canchas, count($canchas_ids)));

    $hora_inicio_base = strtotime($torneo['hora_inicio']);
    $duracion_min = $torneo['tiempo_por_partido'] ?: 20;
    $partidos_creados = 0;

    // Inicializar el historial de asignaciones de cancha para cada pareja
    $pairCourtCount = [];
    foreach ($pairs as $p) {
        if (isset($p['id']) && $p['id'] > 0) {
            $pairCourtCount[$p['id']] = [];
            foreach ($canchas_ids as $court_id) {
                $pairCourtCount[$p['id']][$court_id] = 0;
            }
        }
    }

    if ($tipo_torneo === 'grupos' && $num_pairs === 8) {
        // --- LOGICA PARA 8 PAREJAS EN 2 GRUPOS ---
        shuffle($pairs);
        $grupoA = array_slice($pairs, 0, 4);
        $grupoB = array_slice($pairs, 4, 4);

        // Actualizar tabla participantes con el grupo
        foreach($grupoA as $p) $conn->query("UPDATE torneo_participantes SET grupo_id = 'A' WHERE id = " . $p['id']);
        foreach($grupoB as $p) $conn->query("UPDATE torneo_participantes SET grupo_id = 'B' WHERE id = " . $p['id']);

        $partidos_creados = generateEightPairsGroupsMatches($conn, $torneo, $grupoA, $grupoB, $canchas_ids, $pairCourtCount);
        
    } else {
        // --- LOGICA ROUND ROBIN REAL (Todos contra todos) ---
        $tempPairs = $pairs;
        if (count($tempPairs) % 2 != 0) {
            $tempPairs[] = ['id' => -1, 'BYE' => true]; // Bye
        }
        
        $n = count($tempPairs);
        $rounds = [];
        for ($r = 0; $r < $n - 1; $r++) {
            for ($i = 0; $i < $n / 2; $i++) {
                $p1 = $tempPairs[$i];
                $p2 = $tempPairs[$n - 1 - $i];
                if (!isset($p1['BYE']) && !isset($p2['BYE'])) {
                    $rounds[$r][] = [$p1, $p2];
                }
            }
            // Rotate pairs (Circle Method)
            $last = array_pop($tempPairs);
            array_splice($tempPairs, 1, 0, [$last]);
        }

        // Shuffle rounds for variety
        shuffle($rounds);

        // 1. Group all matches by time slot
        $timeSlots = [];
        $matchIndex = 0;
        foreach ($rounds as $rIdx => $matchesInRound) {
            foreach ($matchesInRound as $mIdx => $pairUps) {
                $rSlot = floor($matchIndex / $num_canchas_a_usar);
                $timeSlots[$rSlot][] = [0 => $pairUps[0], 1 => $pairUps[1], 'grupo' => null];
                $matchIndex++;
            }
        }

        // 2. Allocate courts and insert matches slot-by-slot
        $canchas_slot = array_slice($canchas_ids, 0, $num_canchas_a_usar);
        foreach ($timeSlots as $slotIdx => $slotMatches) {
            $hIni = date('H:i:s', $hora_inicio_base + ($slotIdx * $duracion_min * 60));
            $hFin = date('H:i:s', $hora_inicio_base + (($slotIdx + 1) * $duracion_min * 60));

            // Get optimal court assignment for this slot
            $assignment = getOptimalCourtAssignment($slotMatches, $canchas_slot, $pairCourtCount);

            foreach ($slotMatches as $mIdx => $match) {
                $court_id = $assignment[$mIdx];
                // Find court slot index in the full list for display/database
                $num_cancha = array_search($court_id, $canchas_ids) + 1;

                insertMatch($conn, $torneo_id, $slotIdx + 1, $num_cancha, $hIni, $match[0], $match[1], $match['grupo'], 'Grupos', $court_id, $torneo['fecha'], $hFin);
                $partidos_creados++;

                // Update history
                $p1_id = $match[0]['id'];
                $p2_id = $match[1]['id'];
                if ($p1_id > 0) $pairCourtCount[$p1_id][$court_id] = ($pairCourtCount[$p1_id][$court_id] ?? 0) + 1;
                if ($p2_id > 0) $pairCourtCount[$p2_id][$court_id] = ($pairCourtCount[$p2_id][$court_id] ?? 0) + 1;
            }
        }
    }

    // Asegurar que las canchas utilizadas tengan su reserva de bloque completo 'Americano'
    $conn->query("DELETE FROM reservas_cancha WHERE torneo_id = $torneo_id");

    // Calcular hora de fin según los partidos generados
    $sqlExtremes = "SELECT MIN(hora_inicio) as min_h, MAX(hora_inicio) as max_h FROM torneo_partidos WHERE torneo_id = $torneo_id";
    $resExt = $conn->query($sqlExtremes)->fetch_assoc();
    $minH = $resExt['min_h'] ?? $torneo['hora_inicio'];
    $maxH = $resExt['max_h'] ?? $torneo['hora_inicio'];

    $start_ts = strtotime($minH);
    $raw_end_ts = strtotime($maxH) + ($duracion_min * 60);
    $dur_total_mins = max(90, ceil(($raw_end_ts - $start_ts) / 1800) * 30);
    $end_ts = $start_ts + ($dur_total_mins * 60);
    $h_ini = date('H:i:s', $start_ts);
    $h_fin = date('H:i:s', $end_ts);
    if (date('H:i', $end_ts) === '00:00' || (date('H', $end_ts) == '00' && date('i', $end_ts) == '00')) {
        $h_fin = '24:00:00';
    }

    $canchas_usadas = array_slice($canchas_ids, 0, $num_canchas_a_usar);
    $fec = $torneo['fecha'];

    // Validar colisiones con reservas de otros usuarios
    foreach ($canchas_usadas as $cid) {
        if ($cid > 0) {
            $stmtChk = $conn->prepare("SELECT r.id, r.hora_inicio, r.hora_fin, c.nombre FROM reservas_cancha r JOIN canchas c ON r.cancha_id = c.id WHERE r.cancha_id = ? AND r.fecha = ? AND (r.torneo_id IS NULL OR r.torneo_id != ?) AND LOWER(r.estado) NOT IN ('cancelada', 'cancelado', 'anulada', 'anulado', 'rechazada', 'rechazado', 'eliminada', 'eliminado') AND TIME(r.hora_inicio) < TIME(?) AND TIME(CASE WHEN r.hora_fin IN ('00:00:00','00:00','24:00','24:00:00','23:59','23:59:00') OR TIME(r.hora_fin) <= TIME(r.hora_inicio) THEN '24:00:00' ELSE r.hora_fin END) > TIME(?) LIMIT 1");
            if ($stmtChk) {
                $stmtChk->bind_param("iisss", $cid, $fec, $torneo_id, $h_fin, $h_ini);
                $stmtChk->execute();
                $resChk = $stmtChk->get_result();
                if ($resChk && $rowChk = $resChk->fetch_assoc()) {
                    $conn->query("SET SESSION foreign_key_checks = 1");
                    http_response_code(409);
                    echo json_encode([
                        "error" => "La cancha '" . $rowChk['nombre'] . "' ya se encuentra reservada el $fec entre " . substr($rowChk['hora_inicio'], 0, 5) . " y " . substr($rowChk['hora_fin'], 0, 5) . "."
                    ]);
                    exit;
                }
                $stmtChk->close();
            }
        }
    }

    // Limpiar reservas previas de este torneo antes de reinsertar para evitar duplicados en canchas
    $conn->query("DELETE FROM reservas_cancha WHERE torneo_id = $torneo_id");

    $nomEsc = $conn->real_escape_string($torneo['nombre'] ?? '');
    $cid_club = (int)($torneo['club_id'] ?? 0);
    if (!empty($nomEsc) && $cid_club > 0) {
        $conn->query("DELETE r FROM reservas_cancha r 
                      JOIN canchas c ON r.cancha_id = c.id 
                      WHERE c.club_id = $cid_club 
                        AND r.nombre_externo = '$nomEsc' 
                        AND (r.tipo_reserva = 'americano' OR r.estado = 'Americano')");
    }

    foreach ($canchas_usadas as $cid) {
        $sqlRes = "INSERT INTO reservas_cancha 
                  (cancha_id, usuario_id, fecha, hora_inicio, hora_fin, precio, estado, tipo_reserva, nombre_externo, torneo_id) 
                  VALUES (?, ?, ?, ?, ?, 0, 'Americano', 'americano', ?, ?)";
        $stmtRes = $conn->prepare($sqlRes);
        if ($stmtRes) {
            $nom = $torneo['nombre'];
            $creator = (int)($torneo['creator_id'] ?? 0);
            $stmtRes->bind_param("iissssi", $cid, $creator, $fec, $h_ini, $h_fin, $nom, $torneo_id);
            $stmtRes->execute();
            $stmtRes->close();
        }
    }
    
    $conn->query("SET SESSION foreign_key_checks = 1");
    echo json_encode(["success" => true, "partidos_generados" => $partidos_creados]);

} catch (Throwable $t) {
    $conn->query("SET SESSION foreign_key_checks = 1");
    http_response_code(500); 
    echo json_encode(["error" => "Error interno: " . $t->getMessage(), "line" => $t->getLine()]);
}

/**
 * Función para generar partidos de grupos de 8 parejas
 */
function generateEightPairsGroupsMatches($conn, $torneo, $grupoA, $grupoB, $canchas_ids, &$pairCourtCount) {
    $torneo_id = $torneo['id'];
    $num_canchas = $torneo['num_canchas'];
    $duracion_min = $torneo['tiempo_por_partido'] ?: 20;
    $hora_inicio_base = strtotime($torneo['hora_inicio']);
    $fecha = $torneo['fecha'];
    $creados = 0;

    $robin = [
        1 => [[0, 1], [2, 3]],
        2 => [[0, 2], [1, 3]],
        3 => [[0, 3], [1, 2]]
    ];

    $num_canchas_a_usar = max(1, min((int)$num_canchas, count($canchas_ids)));
    $timeSlots = [];

    // Si tenemos 4 canchas, jugamos Grupo A y B paralelos en 3 rondas totales
    if (count($canchas_ids) >= 4 && $num_canchas >= 4) {
        $canchas_slot = array_slice($canchas_ids, 0, 4);
        foreach ($robin as $r => $matchUps) {
            $timeSlots[$r - 1] = [
                [0 => $grupoA[$matchUps[0][0]], 1 => $grupoA[$matchUps[0][1]], 'grupo' => 'A'],
                [0 => $grupoA[$matchUps[1][0]], 1 => $grupoA[$matchUps[1][1]], 'grupo' => 'A'],
                [0 => $grupoB[$matchUps[0][0]], 1 => $grupoB[$matchUps[0][1]], 'grupo' => 'B'],
                [0 => $grupoB[$matchUps[1][0]], 1 => $grupoB[$matchUps[1][1]], 'grupo' => 'B']
            ];
        }
    } else {
        // Con 2 canchas, secuencial: A R1, B R1, A R2, B R2, A R3, B R3. Total 6 slots.
        $canchas_slot = array_slice($canchas_ids, 0, 2);
        $slotIdx = 0;
        foreach ($robin as $r => $matchUps) {
            // Grupo A matches
            $timeSlots[$slotIdx++] = [
                [0 => $grupoA[$matchUps[0][0]], 1 => $grupoA[$matchUps[0][1]], 'grupo' => 'A'],
                [0 => $grupoA[$matchUps[1][0]], 1 => $grupoA[$matchUps[1][1]], 'grupo' => 'A']
            ];
            // Grupo B matches
            $timeSlots[$slotIdx++] = [
                [0 => $grupoB[$matchUps[0][0]], 1 => $grupoB[$matchUps[0][1]], 'grupo' => 'B'],
                [0 => $grupoB[$matchUps[1][0]], 1 => $grupoB[$matchUps[1][1]], 'grupo' => 'B']
            ];
        }
    }

    // Asignar canchas optimizadas slot-por-slot e insertar partidos
    foreach ($timeSlots as $slotIdx => $slotMatches) {
        $hIni = date('H:i:s', $hora_inicio_base + ($slotIdx * $duracion_min * 60));
        $hFin = date('H:i:s', $hora_inicio_base + (($slotIdx + 1) * $duracion_min * 60));

        // Get optimal court assignment for this slot
        $assignment = getOptimalCourtAssignment($slotMatches, $canchas_slot, $pairCourtCount);

        foreach ($slotMatches as $mIdx => $match) {
            $court_id = $assignment[$mIdx];
            // Encontrar el número físico de cancha (1-indexed en base al array completo de canchas_ids)
            $num_cancha = array_search($court_id, $canchas_ids) + 1;

            insertMatch($conn, $torneo_id, $slotIdx + 1, $num_cancha, $hIni, $match[0], $match[1], $match['grupo'], 'Grupos', $court_id, $fecha, $hFin);
            $creados++;

            // Actualizar historial
            $p1_id = $match[0]['id'];
            $p2_id = $match[1]['id'];
            if ($p1_id > 0) $pairCourtCount[$p1_id][$court_id] = ($pairCourtCount[$p1_id][$court_id] ?? 0) + 1;
            if ($p2_id > 0) $pairCourtCount[$p2_id][$court_id] = ($pairCourtCount[$p2_id][$court_id] ?? 0) + 1;
        }
    }

    return $creados;
}

/**
 * Encuentra la asignación óptima de canchas para un slot de tiempo
 * minimizando la repetición de canchas para cada pareja.
 */
function getOptimalCourtAssignment($slotMatches, $canchas_ids, $pairCourtCount) {
    $bestAssignment = [];
    $minCost = INF;

    // Usamos una función recursiva de backtracking para evaluar todas las permutaciones de canchas
    $assign = function($matchIdx, $assignedCourts, $currentCost, $currentAssignment) use (&$assign, &$bestAssignment, &$minCost, $slotMatches, $canchas_ids, $pairCourtCount) {
        if ($currentCost >= $minCost) {
            return; // Poda si ya superamos el costo mínimo actual
        }
        if ($matchIdx == count($slotMatches)) {
            $minCost = $currentCost;
            $bestAssignment = $currentAssignment;
            return;
        }

        $match = $slotMatches[$matchIdx];
        $p1_id = $match[0]['id'] ?? 0;
        $p2_id = $match[1]['id'] ?? 0;

        foreach ($canchas_ids as $court_id) {
            if (in_array($court_id, $assignedCourts)) {
                continue; // La cancha ya está asignada a otro partido en este mismo horario
            }

            // Calcular el costo (cuántas veces han jugado estas parejas en esta cancha anteriormente)
            $cost1 = ($p1_id > 0 && isset($pairCourtCount[$p1_id][$court_id])) ? $pairCourtCount[$p1_id][$court_id] : 0;
            $cost2 = ($p2_id > 0 && isset($pairCourtCount[$p2_id][$court_id])) ? $pairCourtCount[$p2_id][$court_id] : 0;
            $addedCost = $cost1 + $cost2;

            $newAssigned = $assignedCourts;
            $newAssigned[] = $court_id;

            $newAssignment = $currentAssignment;
            $newAssignment[$matchIdx] = $court_id;

            $assign($matchIdx + 1, $newAssigned, $currentCost + $addedCost, $newAssignment);
        }
    };

    $assign(0, [], 0, []);
    return $bestAssignment;
}
?>
