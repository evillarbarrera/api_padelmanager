<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$categoria_id = $data['categoria_id'] ?? 0;

if (!$categoria_id) {
    echo json_encode(["error" => "categoria_id es requerido"]);
    exit;
}

/**
 * 1. OBTENER CLASIFICADOS (Ranking real)
 */
$sqlGrupos = "SELECT id, nombre FROM torneo_grupos WHERE categoria_id = ? ORDER BY nombre ASC";
$stmtG = $conn->prepare($sqlGrupos);
$stmtG->bind_param("i", $categoria_id);
$stmtG->execute();
$resGrupos = $stmtG->get_result();

$firsts = []; // 1eros de grupo
$seconds = []; // 2dos de grupo

while ($g = $resGrupos->fetch_assoc()) {
    $gid = $g['id'];
    $gnombre = $g['nombre'];
    
    // Basado en el prompt: Partidos ganados > sets diff > games diff
    $sqlRanking = "SELECT pareja_id, pg, sf-sc as dif_sets, gf-gc as dif_games 
                   FROM torneo_grupo_parejas 
                   WHERE grupo_id = ? 
                   ORDER BY pg DESC, dif_sets DESC, dif_games DESC 
                   LIMIT 2";
    $stmtR = $conn->prepare($sqlRanking);
    $stmtR->bind_param("i", $gid);
    $stmtR->execute();
    $rR = $stmtR->get_result();
    
    if ($r1 = $rR->fetch_assoc()) $firsts[$gnombre] = $r1['pareja_id'];
    if ($r2 = $rR->fetch_assoc()) $seconds[$gnombre] = $r2['pareja_id'];
}

/**
 * 2. LIMPIAR CUADRO PREVIO
 */
$conn->query("DELETE FROM torneo_partidos_cuadro WHERE categoria_id = $categoria_id");

/**
 * 3. GENERAR ESTRUCTURA DE OCTAVOS (Nivel 4)
 * Creamos los partidos vacíos primero para obtener los IDs y vincularlos.
 */

// Función para crear árbol de IDs (Simplificado para este ejemplo)
// En un entorno real se crearían de Final hacia atrás para tener los siguiente_partido_id.

// FINAL (Nivel 1)
$conn->query("INSERT INTO torneo_partidos_cuadro (categoria_id, nivel, lado_cuadro, posicion) VALUES ($categoria_id, 1, 'izq', 0)");
$final_id = $conn->insert_id;

// SEMIS (Nivel 2)
$conn->query("INSERT INTO torneo_partidos_cuadro (categoria_id, nivel, lado_cuadro, posicion, siguiente_partido_id) VALUES ($categoria_id, 2, 'izq', 0, $final_id)");
$semi_izq_id = $conn->insert_id;
$conn->query("INSERT INTO torneo_partidos_cuadro (categoria_id, nivel, lado_cuadro, posicion, siguiente_partido_id) VALUES ($categoria_id, 2, 'der', 1, $final_id)");
$semi_der_id = $conn->insert_id;

// CUARTOS (Nivel 3)
$cuartos_ids = [];
for ($i=0; $i<4; $i++) {
    $lado = $i < 2 ? 'izq' : 'der';
    $next = $lado === 'izq' ? $semi_izq_id : $semi_der_id;
    $conn->query("INSERT INTO torneo_partidos_cuadro (categoria_id, nivel, lado_cuadro, posicion, siguiente_partido_id) VALUES ($categoria_id, 3, '$lado', $i, $next)");
    $cuartos_ids[$i] = $conn->insert_id;
}

// OCTAVOS (Nivel 4)
$octavos_ids = [];
for ($i=0; $i<8; $i++) {
    $lado = $i < 4 ? 'izq' : 'der';
    $next = $cuartos_ids[floor($i/2)];
    $conn->query("INSERT INTO torneo_partidos_cuadro (categoria_id, nivel, lado_cuadro, posicion, siguiente_partido_id) VALUES ($categoria_id, 4, '$lado', $i, $next)");
    $octavos_ids[$i] = $conn->insert_id;
}

/**
 * 4. POBLAR OCTAVOS CON CRUCES (CROSSOVER)
 * Lado Izq (0,1,2,3):
 * Part 0: 1ºA vs 2ºB
 * Part 1: 1ºC vs 2ºD
 * Part 2: 1ºE vs 2ºF
 * Part 3: 1ºG vs 2ºH
 * 
 * Lado Der (4,5,6,7):
 * Part 4: 1ºB vs 2ºA
 * Part 5: 1ºD vs 2ºC
 * Part 6: 1ºF vs 2ºE
 * Part 7: 1ºH vs 2ºG
 */

$cruces = [
    0 => ['1' => 'A', '2' => 'B'],
    1 => ['1' => 'C', '2' => 'D'],
    2 => ['1' => 'E', '2' => 'F'],
    3 => ['1' => 'G', '2' => 'H'],
    4 => ['1' => 'B', '2' => 'A'],
    5 => ['1' => 'D', '2' => 'C'],
    6 => ['1' => 'F', '2' => 'E'],
    7 => ['1' => 'H', '2' => 'G']
];

foreach ($cruces as $idx => $c) {
    $mid = $octavos_ids[$idx];
    $p1 = $firsts[$c['1']] ?? null;
    $p2 = $seconds[$c['2']] ?? null;
    
    if ($p1 || $p2) {
        $sqlUpd = "UPDATE torneo_partidos_cuadro SET pareja1_id = ?, pareja2_id = ? WHERE id = ?";
        $stmtU = $conn->prepare($sqlUpd);
        $stmtU->bind_param("iii", $p1, $p2, $mid);
        $stmtU->execute();
    }
}

echo json_encode(["success" => true, "message" => "Cuadro de Octavos generado con éxito."]);
?>
