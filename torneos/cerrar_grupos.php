<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

/**
 * CIERRE DE FASE DE GRUPOS Y GENERACIÓN DE PLAYOFFS V3 (Dynamic Seeding)
 */

$data = json_decode(file_get_contents("php://input"), true);
$categoria_id = $data['categoria_id'] ?? 0;

if (!$categoria_id) {
    http_response_code(400);
    echo json_encode(["error" => "ID de categoría requerido"]);
    exit;
}

// 1. Obtener todos los grupos clasificados (Ranking real)
$sqlGrupos = "SELECT id, nombre FROM torneo_grupos WHERE categoria_id = ? ORDER BY nombre ASC";
$stmtG = $conn->prepare($sqlGrupos);
$stmtG->bind_param("i", $categoria_id);
$stmtG->execute();
$resGrupos = $stmtG->get_result();

$firsts = [];
$seconds = [];
$groupNames = [];

while ($g = $resGrupos->fetch_assoc()) {
    $gid = $g['id'];
    $gn = $g['nombre'];
    $groupNames[] = $gn;
    
    // Clasificación: Partidos Ganados > Diferencia de Sets > Diferencia de Juegos
    $sqlRank = "SELECT pareja_id FROM torneo_grupo_parejas 
                WHERE grupo_id = ? 
                ORDER BY pg DESC, (sf-sc) DESC, (gf-gc) DESC 
                LIMIT 2";
    $stmtR = $conn->prepare($sqlRank);
    $stmtR->bind_param("i", $gid);
    $stmtR->execute();
    $rR = $stmtR->get_result();
    
    if ($r1 = $rR->fetch_assoc()) $firsts[$gn] = $r1['pareja_id'];
    if ($r2 = $rR->fetch_assoc()) $seconds[$gn] = $r2['pareja_id'];
}

$numGroups = count($groupNames);

// 2. Limpiar cuadro previo
$conn->query("DELETE FROM torneo_partidos_cuadro WHERE categoria_id = $categoria_id");

// 3. Determinar nivel inicial
// 9-16 grupos -> 18-32 parejas -> Dieciseisavos (Nivel 5)
// 5-8 grupos  -> 10-16 parejas -> Octavos (Nivel 4)
// 3-4 grupos  -> 6-8 parejas   -> Cuartos (Nivel 3)
// 2 grupos    -> 4 parejas     -> Semis (Nivel 2)
// 1 grupo     -> 2 parejas     -> Final (Nivel 1)
$startLevel = 1;
if ($numGroups >= 9) $startLevel = 5;
else if ($numGroups >= 5) $startLevel = 4;
else if ($numGroups >= 3) $startLevel = 3;
else if ($numGroups >= 2) $startLevel = 2;

// 4. Generar estructura de brackets (de Final hacia atrás)
$bracket_ids = []; // nivel => [ids]

// FINAL (Nivel 1)
$conn->query("INSERT INTO torneo_partidos_cuadro (categoria_id, nivel, lado_cuadro, posicion) VALUES ($categoria_id, 1, 'izq', 0)");
$bracket_ids[1] = [$conn->insert_id];

// Generar niveles intermedios hasta el inicio
for ($l = 2; $l <= $startLevel; $l++) {
    $numMatches = pow(2, $l - 1);
    $bracket_ids[$l] = [];
    
    for ($i = 0; $i < $numMatches; $i++) {
        $lado = ($i < $numMatches / 2) ? 'izq' : 'der';
        $parentIdx = floor($i / 2);
        if ($l - 1 === 1) $parentIdx = 0;
        
        $next_id = $bracket_ids[$l - 1][$parentIdx];
        
        $sql = "INSERT INTO torneo_partidos_cuadro (categoria_id, nivel, lado_cuadro, posicion, siguiente_partido_id) 
                VALUES ($categoria_id, $l, '$lado', $i, $next_id)";
        $conn->query($sql);
        $bracket_ids[$l][] = $conn->insert_id;
    }
}

// 5. Población de Cruces (Crossover Seeding)
$cruces_config = [
    5 => [ // Dieciseisavos (16 partidos)
        0 => ['1' => 'A', '2' => 'B'], 1 => ['1' => 'C', '2' => 'D'],
        2 => ['1' => 'E', '2' => 'F'], 3 => ['1' => 'G', '2' => 'H'],
        4 => ['1' => 'I', '2' => 'J'], 5 => ['1' => 'K', '2' => 'L'],
        6 => ['1' => 'M', '2' => 'N'], 7 => ['1' => 'O', '2' => 'P'],
        8 => ['1' => 'B', '2' => 'A'], 9 => ['1' => 'D', '2' => 'C'],
        10 => ['1' => 'F', '2' => 'E'], 11 => ['1' => 'H', '2' => 'G'],
        12 => ['1' => 'J', '2' => 'I'], 13 => ['1' => 'L', '2' => 'K'],
        14 => ['1' => 'N', '2' => 'M'], 15 => ['1' => 'P', '2' => 'O']
    ],
    4 => [ // Octavos (8 partidos)
        0 => ['1' => 'A', '2' => 'B'], 1 => ['1' => 'C', '2' => 'D'], 
        2 => ['1' => 'E', '2' => 'F'], 3 => ['1' => 'G', '2' => 'H'],
        4 => ['1' => 'B', '2' => 'A'], 5 => ['1' => 'D', '2' => 'C'], 
        6 => ['1' => 'F', '2' => 'E'], 7 => ['1' => 'H', '2' => 'G']
    ],
    3 => [ // Cuartos (4 partidos)
        0 => ['1' => 'A', '2' => 'B'], 1 => ['1' => 'C', '2' => 'D'],
        2 => ['1' => 'B', '2' => 'A'], 3 => ['1' => 'D', '2' => 'C']
    ],
    2 => [ // Semis (2 partidos)
        0 => ['1' => 'A', '2' => 'B'], 
        1 => ['1' => 'B', '2' => 'A']
    ]
];

$cruces = $cruces_config[$startLevel] ?? [];

foreach ($cruces as $idx => $c) {
    if (isset($bracket_ids[$startLevel][$idx])) {
        $mid = $bracket_ids[$startLevel][$idx];
        $p1 = $firsts[$c['1']] ?? null;
        $p2 = $seconds[$c['2']] ?? null;
        
        if ($p1 || $p2) {
            $sqlU = "UPDATE torneo_partidos_cuadro SET pareja1_id = ?, pareja2_id = ? WHERE id = ?";
            $stU = $conn->prepare($sqlU);
            $stU->bind_param("iii", $p1, $p2, $mid);
            $stU->execute();
        }
    }
}

$conn->query("UPDATE torneo_categorias SET estado = 'Playoffs' WHERE id = $categoria_id");

$nombres_niveles = [5 => "Dieciseisavos", 4 => "Octavos", 3 => "Cuartos", 2 => "Semifinales", 1 => "Final"];
$ronda_txt = $nombres_niveles[$startLevel] ?? "Playoffs";

echo json_encode([
    "success" => true, 
    "mensaje" => "Fase de grupos cerrada. Se ha generado el cuadro desde $ronda_txt.",
    "ronda_inicial" => $ronda_txt,
    "num_grupos" => $numGroups
]);
?>

