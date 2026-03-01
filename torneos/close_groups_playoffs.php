<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$torneo_id = $data['torneo_id'] ?? 0;

if (!$torneo_id) {
    http_response_code(400);
    echo json_encode(["error" => "torneo_id es requerido"]);
    exit;
}

// 1. Obtener partidos de grupos
$sqlMatches = "SELECT * FROM torneo_partidos WHERE torneo_id = ? AND fase = 'Grupos'";
$stmtM = $conn->prepare($sqlMatches);
$stmtM->bind_param("i", $torneo_id);
$stmtM->execute();
$matches = $stmtM->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($matches as $m) {
    if ($m['finalizado'] == 0) {
        http_response_code(400);
        echo json_encode(["error" => "Aún hay partidos de grupo sin finalizar. No se pueden generar los playoffs."]);
        exit;
    }
}

// 2. Calcular posiciones
function getStandings($torneo_id, $grupo_id, $conn) {
    $sql = "SELECT p.id, p.jugador_id, p.jugador2_id, p.nombre_externo_1, p.nombre_externo_2
            FROM torneo_participantes p
            WHERE p.torneo_id = ? AND p.grupo_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $torneo_id, $grupo_id);
    $stmt->execute();
    $pairs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $stats = [];
    foreach ($pairs as $p) {
        $stats[$p['id']] = [
            'pair' => $p,
            'wins' => 0,
            'sets_won' => 0,
            'sets_lost' => 0,
            'games_won' => 0,
            'games_lost' => 0
        ];
    }

    $sqlP = "SELECT * FROM torneo_partidos WHERE torneo_id = ? AND grupo_id = ? AND fase = 'Grupos' AND finalizado = 1";
    $stmtP = $conn->prepare($sqlP);
    $stmtP->bind_param("is", $torneo_id, $grupo_id);
    $stmtP->execute();
    $groupMatches = $stmtP->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($groupMatches as $m) {
        $p1_id = null; $p2_id = null;
        // Identificar qué pareja es cual basándose en jugadores o nombres? 
        // Mejor buscar en participantes por los ids de los jugadores del partido.
        // Pero el partido tiene jugador1_id, jugador2_id (Pareja A) y jugador3_id, jugador4_id (Pareja B).
        // Necesitamos el ID de la tabla torneo_participantes.
        // Vamos a buscar la pareja que tiene jugador1_id y jugador2_id.
        $pairA = findPairId($conn, $torneo_id, $m['jugador1_id'], $m['jugador2_id'], $m['nombre_externo_1'], $m['nombre_externo_2']);
        $pairB = findPairId($conn, $torneo_id, $m['jugador3_id'], $m['jugador4_id'], $m['nombre_externo_3'], $m['nombre_externo_4']);

        if ($pairA && $pairB) {
            $stats[$pairA]['games_won'] += $m['puntos_t1'];
            $stats[$pairA]['games_lost'] += $m['puntos_t2'];
            $stats[$pairB]['games_won'] += $m['puntos_t2'];
            $stats[$pairB]['games_lost'] += $m['puntos_t1'];

            if ($m['puntos_t1'] > $m['puntos_t2']) {
                $stats[$pairA]['wins']++;
            } else if ($m['puntos_t2'] > $m['puntos_t1']) {
                $stats[$pairB]['wins']++;
            }
        }
    }

    // Ordenar por Ganados, luego Diferencia de Games
    uasort($stats, function($a, $b) {
        if ($a['wins'] != $b['wins']) return $b['wins'] - $a['wins'];
        $diffA = $a['games_won'] - $a['games_lost'];
        $diffB = $b['games_won'] - $b['games_lost'];
        return $diffB - $diffA;
    });

    return array_values($stats);
}

function findPairId($conn, $torneo_id, $j1, $j2, $n1, $n2) {
    if ($j1) {
        $sql = "SELECT id FROM torneo_participantes WHERE torneo_id = ? AND jugador_id = ? AND (jugador2_id = ? OR (jugador2_id IS NULL AND ? IS NULL))";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiii", $torneo_id, $j1, $j2, $j2);
    } else {
        $sql = "SELECT id FROM torneo_participantes WHERE torneo_id = ? AND nombre_externo_1 = ? AND nombre_externo_2 = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iss", $torneo_id, $n1, $n2);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['id'] ?? null;
}

$standingsA = getStandings($torneo_id, 'A', $conn);
$standingsB = getStandings($torneo_id, 'B', $conn);

if (count($standingsA) < 4 || count($standingsB) < 4) {
    http_response_code(400);
    echo json_encode(["error" => "No hay suficientes parejas en los grupos (se necesitan 4 por grupo)."]);
    exit;
}

// 3. Generar Semifinales
// Borrar playoffs previos si existen
$conn->query("DELETE FROM torneo_partidos WHERE torneo_id = $torneo_id AND fase != 'Grupos'");

$sqlTorneo = "SELECT * FROM torneos_americanos WHERE id = $torneo_id";
$torneo = $conn->query($sqlTorneo)->fetch_assoc();
$hIni = strtotime($torneo['hora_inicio']) + (3 * $torneo['tiempo_por_partido'] * 60); // Después de 3 rondas
if ($torneo['num_canchas'] < 4) $hIni += (3 * $torneo['tiempo_por_partido'] * 60); // 6 rondas si secuencial

$fecha = $torneo['fecha'];
$dur = $torneo['tiempo_por_partido'] ?: 20;

require_once "match_utils.php";

// Semifinal Oro
// S1: 1°A vs 2°B (Ronda 7)
// S2: 1°B vs 2°A (Ronda 7) -> Logic: standingsB[0] (1st B) vs standingsA[1] (2nd A)
// Note: Frontend prefers phase name 'Semifinales' to group them.
// We use group_id='Oro' and group_id='Plata' to separate cups inside 'Semifinales'.

$rS = 7; 
$hS1 = date('H:i:s', $hIni);
$hS2 = date('H:i:s', $hIni + ($dur * 60)); // Not really needed if simultaneous generally, but let's keep logic

// ORO - Semifinales
insertMatch($conn, $torneo_id, $rS, 1, $hS1, $standingsA[0]['pair'], $standingsB[1]['pair'], 'Oro', 'Semifinales', null, $fecha, date('H:i:s', $hIni + $dur*60));
insertMatch($conn, $torneo_id, $rS, 2, $hS1, $standingsB[0]['pair'], $standingsA[1]['pair'], 'Oro', 'Semifinales', null, $fecha, date('H:i:s', $hIni + $dur*60));

// PLATA - Semifinales
// 3°A vs 4°B
// 3°B vs 4°A
insertMatch($conn, $torneo_id, $rS, 3, $hS1, $standingsA[2]['pair'], $standingsB[3]['pair'], 'Plata', 'Semifinales', null, $fecha, date('H:i:s', $hIni + $dur*60));
insertMatch($conn, $torneo_id, $rS, 4, $hS1, $standingsB[2]['pair'], $standingsA[3]['pair'], 'Plata', 'Semifinales', null, $fecha, date('H:i:s', $hIni + $dur*60));

echo json_encode(["success" => true, "message" => "Semifinales generadas. Ve a la pestaña 'Semifinales'."]);
