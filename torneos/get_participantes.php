<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

$headers = getallheaders();
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';

if (empty($auth)) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$torneo_id = $_GET['torneo_id'] ?? 0;

if (!$torneo_id) {
    echo json_encode([]);
    exit;
}

// 1. Obtener Participantes (Parejas) y armar mapa de búsqueda
$sql = "SELECT tp.*, 
               COALESCE(u1.nombre, tp.nombre_externo_1, 'Jugador 1') as jugador1_nombre, u1.usuario as jugador1_email,
               COALESCE(u2.nombre, tp.nombre_externo_2, 'Jugador 2') as jugador2_nombre, u2.usuario as jugador2_email
         FROM torneo_participantes tp 
         LEFT JOIN usuarios u1 ON tp.jugador_id = u1.id 
         LEFT JOIN usuarios u2 ON tp.jugador2_id = u2.id 
         WHERE tp.torneo_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $torneo_id);
$stmt->execute();
$resP = $stmt->get_result();

$participantes = [];
// Map player_id/name -> Array of couple_indices (handling multiple couples per player)
$playerMap = []; 
$i = 0;

while ($row = $resP->fetch_assoc()) {
    // Inicializar stats
    $row['partidos_jugados'] = 0;
    $row['partidos_ganados'] = 0;
    $row['partidos_perdidos'] = 0;
    $row['diferencia_games'] = 0;
    $row['games_ganados'] = 0;  // New
    $row['games_perdidos'] = 0; // New
    $row['puntos_totales'] = 0; 
    
    // Normalizar datos para matching
    $row['p1_id'] = $row['jugador_id'];
    $row['p1_name'] = strtolower($row['nombre_externo_1'] ?? $row['jugador1_nombre'] ?? '');
    $row['p2_id'] = $row['jugador2_id'];
    $row['p2_name'] = strtolower($row['nombre_externo_2'] ?? $row['jugador2_nombre'] ?? '');

    $participantes[$i] = $row;
    
    // Mapear IDs de jugadores al índice de la pareja (Push to array)
    if ($row['jugador_id']) {
        $playerMap['id_' . $row['jugador_id']][] = $i;
    } else if (!empty($row['nombre_externo_1'])) {
        $playerMap['name_' . strtolower($row['nombre_externo_1'])][] = $i;
    }
    
    if ($row['jugador2_id']) {
        $playerMap['id_' . $row['jugador2_id']][] = $i;
    } else if (!empty($row['nombre_externo_2'])) {
        $playerMap['name_' . strtolower($row['nombre_externo_2'])][] = $i;
    }
    
    $i++;
}

// Helper para encontrar la pareja correcta dado un jugador y su compañero (contexto)
function identifyCoupleIndex($participantes, $candidates, $partnerId, $partnerName) {
    if (empty($candidates)) return -1;
    if (count($candidates) === 1) return $candidates[0]; // Sin ambigüedad

    $pName = strtolower($partnerName ?? '');
    
    foreach ($candidates as $idx) {
        $couple = $participantes[$idx];
        // Chequear si el partner coincide con p1 o p2 de esta pareja
        if (
            ($partnerId && ($couple['p1_id'] == $partnerId || $couple['p2_id'] == $partnerId)) ||
            ($pName && (strpos($couple['p1_name'], $pName) !== false || strpos($couple['p2_name'], $pName) !== false))
        ) {
            return $idx;
        }
    }
    // Si no encontramos match por partner, devolvemos el primero (fallback)
    return $candidates[0];
}

// 2. Obtener Partidos Finalizados
$sqlM = "SELECT * FROM torneo_partidos WHERE torneo_id = ? AND finalizado = 1";
$stmtM = $conn->prepare($sqlM);
$stmtM->bind_param("i", $torneo_id);
$stmtM->execute();
$resM = $stmtM->get_result();

while ($m = $resM->fetch_assoc()) {
    // --- Identificar Pareja 1 (Team 1) ---
    $candidates1 = [];
    $p1_key = ''; $p2_key = ''; // keys for identifying MAIN player of team 1
    
    // Try via Player 1 ID/Name
    if ($m['jugador1_id']) $candidates1 = $playerMap['id_' . $m['jugador1_id']] ?? [];
    else if ($m['nombre_externo_1']) $candidates1 = $playerMap['name_' . strtolower($m['nombre_externo_1'])] ?? [];
    
    // Resolve ambiguity using Partner (Player 2)
    $idx1 = identifyCoupleIndex($participantes, $candidates1, $m['jugador2_id'], $m['nombre_externo_2']);

    // --- Identificar Pareja 2 (Team 2) ---
    $candidates2 = [];
    // Try via Player 3 ID/Name
    if ($m['jugador3_id']) $candidates2 = $playerMap['id_' . $m['jugador3_id']] ?? [];
    else if ($m['nombre_externo_3']) $candidates2 = $playerMap['name_' . strtolower($m['nombre_externo_3'])] ?? [];
    
    // Resolve ambiguity using Partner (Player 4)
    $idx2 = identifyCoupleIndex($participantes, $candidates2, $m['jugador4_id'], $m['nombre_externo_4']);
    
    if ($idx1 === -1 || $idx2 === -1) continue; 
    if ($idx1 === $idx2) continue; 

    $pt1 = (int)$m['puntos_t1'];
    $pt2 = (int)$m['puntos_t2'];

    // Update Stats Pair 1
    $participantes[$idx1]['partidos_jugados']++;
    $participantes[$idx1]['diferencia_games'] += ($pt1 - $pt2);
    $participantes[$idx1]['games_ganados'] += $pt1;
    $participantes[$idx1]['games_perdidos'] += $pt2;
    
    // Update Stats Pair 2
    $participantes[$idx2]['partidos_jugados']++;
    $participantes[$idx2]['diferencia_games'] += ($pt2 - $pt1);
    $participantes[$idx2]['games_ganados'] += $pt2;
    $participantes[$idx2]['games_perdidos'] += $pt1;

    if ($pt1 > $pt2) {
        $participantes[$idx1]['partidos_ganados']++;
        $participantes[$idx2]['partidos_perdidos']++;
    } elseif ($pt2 > $pt1) {
        $participantes[$idx2]['partidos_ganados']++;
        $participantes[$idx1]['partidos_perdidos']++;
    }
}

// 4. Identificar Posiciones Finales (Copa Oro / Plata)
$finalPositions = []; 

$resM->data_seek(0);
while ($m = $resM->fetch_assoc()) {
    if ($m['fase'] === 'Finales') {
        $ganador = ($m['puntos_t1'] > $m['puntos_t2']) ? 1 : 2;
        
        $candidates1 = [];
        if ($m['jugador1_id']) $candidates1 = $playerMap['id_' . $m['jugador1_id']] ?? [];
        else if ($m['nombre_externo_1']) $candidates1 = $playerMap['name_' . strtolower($m['nombre_externo_1'])] ?? [];
        $idx1 = identifyCoupleIndex($participantes, $candidates1, $m['jugador2_id'], $m['nombre_externo_2']);

        $candidates2 = [];
        if ($m['jugador3_id']) $candidates2 = $playerMap['id_' . $m['jugador3_id']] ?? [];
        else if ($m['nombre_externo_3']) $candidates2 = $playerMap['name_' . strtolower($m['nombre_externo_3'])] ?? [];
        $idx2 = identifyCoupleIndex($participantes, $candidates2, $m['jugador4_id'], $m['nombre_externo_4']);

        if ($idx1 === -1 || $idx2 === -1) continue;

        if ($m['grupo_id'] === 'Oro') {
            if ($ganador === 1) {
                $finalPositions[$idx1] = 1; 
                $finalPositions[$idx2] = 2; 
            } else {
                $finalPositions[$idx1] = 2;
                $finalPositions[$idx2] = 1;
            }
        } elseif ($m['grupo_id'] === 'Plata') {
            if ($ganador === 1) {
                $finalPositions[$idx1] = 3; 
                $finalPositions[$idx2] = 4;
            } else {
                $finalPositions[$idx1] = 4;
                $finalPositions[$idx2] = 3;
            }
        }
    }
}

// 3. Calcular Puntos Totales y Ordenar
foreach ($participantes as $index => &$p) {
    $p['puntos_totales'] = $p['partidos_ganados'];
    
    // Mark final rank if exists
    if (isset($finalPositions[$index])) {
        $p['final_rank'] = $finalPositions[$index];
    } else {
        $p['final_rank'] = 999; 
    }
    
    unset($p['p1_id'], $p['p1_name'], $p['p2_id'], $p['p2_name']);
}
unset($p);

usort($participantes, function ($a, $b) {
    if (isset($a['final_rank']) && isset($b['final_rank']) && $a['final_rank'] !== $b['final_rank']) {
        return $a['final_rank'] - $b['final_rank'];
    }

    if ($b['partidos_ganados'] !== $a['partidos_ganados']) {
        return $b['partidos_ganados'] - $a['partidos_ganados'];
    }
    if ($b['diferencia_games'] !== $a['diferencia_games']) {
        return $b['diferencia_games'] - $a['diferencia_games'];
    }
    return $b['games_ganados'] - $a['games_ganados'];
});

echo json_encode($participantes);
