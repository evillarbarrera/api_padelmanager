<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
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

    if ($tipo_torneo === 'grupos' && $num_pairs === 8) {
        // --- LOGICA PARA 8 PAREJAS EN 2 GRUPOS ---
        shuffle($pairs);
        $grupoA = array_slice($pairs, 0, 4);
        $grupoB = array_slice($pairs, 4, 4);

        // Actualizar tabla participantes con el grupo
        foreach($grupoA as $p) $conn->query("UPDATE torneo_participantes SET grupo_id = 'A' WHERE id = " . $p['id']);
        foreach($grupoB as $p) $conn->query("UPDATE torneo_participantes SET grupo_id = 'B' WHERE id = " . $p['id']);

        $partidos_creados = generateEightPairsGroupsMatches($conn, $torneo, $grupoA, $grupoB, $canchas_ids);
        
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

        $matchIndex = 0;
        foreach ($rounds as $rIdx => $matchesInRound) {
            foreach ($matchesInRound as $mIdx => $pairUps) {
                // Distribute matches across available courts and time slots
                // r = current time slot index
                $rSlot = floor($matchIndex / $num_canchas_a_usar);
                $cSlot = $matchIndex % $num_canchas_a_usar;
                
                $hIni = date('H:i:s', $hora_inicio_base + ($rSlot * $duracion_min * 60));
                $hFin = date('H:i:s', $hora_inicio_base + (($rSlot + 1) * $duracion_min * 60));
                
                insertMatch($conn, $torneo_id, $rSlot + 1, $cSlot + 1, $hIni, $pairUps[0], $pairUps[1], null, 'Grupos', $canchas_ids[$cSlot], $torneo['fecha'], $hFin);
                $partidos_creados++;
                $matchIndex++;
            }
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
function generateEightPairsGroupsMatches($conn, $torneo, $grupoA, $grupoB, $canchas_ids) {
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

    // Si tenemos 4 canchas, jugamos Grupo A y B paralelos en 3 rondas totales
    if (count($canchas_ids) >= 4 && $num_canchas >= 4) {
        foreach ($robin as $r => $matchUps) {
            $hIni = date('H:i:s', $hora_inicio_base + (($r-1) * $duracion_min * 60));
            $hFin = date('H:i:s', $hora_inicio_base + ($r * $duracion_min * 60));
            // A en C1,C2
            insertMatch($conn, $torneo_id, $r, 1, $hIni, $grupoA[$matchUps[0][0]], $grupoA[$matchUps[0][1]], 'A', 'Grupos', $canchas_ids[0]??null, $fecha, $hFin);
            insertMatch($conn, $torneo_id, $r, 2, $hIni, $grupoA[$matchUps[1][0]], $grupoA[$matchUps[1][1]], 'A', 'Grupos', $canchas_ids[1]??null, $fecha, $hFin);
            // B en C3,C4
            insertMatch($conn, $torneo_id, $r, 3, $hIni, $grupoB[$matchUps[0][0]], $grupoB[$matchUps[0][1]], 'B', 'Grupos', $canchas_ids[2]??null, $fecha, $hFin);
            insertMatch($conn, $torneo_id, $r, 4, $hIni, $grupoB[$matchUps[1][0]], $grupoB[$matchUps[1][1]], 'B', 'Grupos', $canchas_ids[3]??null, $fecha, $hFin);
            $creados += 4;
        }
    } else {
        // Con 2 canchas, jugamos secuencial: Primero R1 A, luego R1 B, etc. Total 6 bloques de tiempo.
        $real_ronda = 1;
        foreach ($robin as $r => $matchUps) {
            $hIni = date('H:i:s', $hora_inicio_base + (($real_ronda-1) * $duracion_min * 60));
            $hFin = date('H:i:s', $hora_inicio_base + ($real_ronda * $duracion_min * 60));
            insertMatch($conn, $torneo_id, $real_ronda, 1, $hIni, $grupoA[$matchUps[0][0]], $grupoA[$matchUps[0][1]], 'A', 'Grupos', $canchas_ids[0]??null, $fecha, $hFin);
            insertMatch($conn, $torneo_id, $real_ronda, 2, $hIni, $grupoA[$matchUps[1][0]], $grupoA[$matchUps[1][1]], 'A', 'Grupos', $canchas_ids[1]??null, $fecha, $hFin);
            $creados += 2; $real_ronda++;

            $hIni = date('H:i:s', $hora_inicio_base + (($real_ronda-1) * $duracion_min * 60));
            $hFin = date('H:i:s', $hora_inicio_base + ($real_ronda * $duracion_min * 60));
            insertMatch($conn, $torneo_id, $real_ronda, 1, $hIni, $grupoB[$matchUps[0][0]], $grupoB[$matchUps[0][1]], 'B', 'Grupos', $canchas_ids[0]??null, $fecha, $hFin);
            insertMatch($conn, $torneo_id, $real_ronda, 2, $hIni, $grupoB[$matchUps[1][0]], $grupoB[$matchUps[1][1]], 'B', 'Grupos', $canchas_ids[1]??null, $fecha, $hFin);
            $creados += 2; $real_ronda++;
        }
    }
    return $creados;
}
?>
