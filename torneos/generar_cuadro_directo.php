<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$categoria_id = (int)($data['categoria_id'] ?? 0);

if (!$categoria_id) {
    http_response_code(400);
    echo json_encode(["error" => "ID de categoría es requerido"]);
    exit;
}

// 1. Obtener todas las parejas inscritas y validadas
$sql = "SELECT i.id, i.pareja_id, i.es_semilla, i.nro_siembra,
        p.nombre_pareja,
        COALESCE(u1.nombre, p.jugador1_nombre_manual) as j1,
        COALESCE(u2.nombre, p.jugador2_nombre_manual) as j2
        FROM torneo_inscripciones i
        JOIN torneo_parejas p ON i.pareja_id = p.id
        LEFT JOIN usuarios u1 ON p.jugador1_id = u1.id
        LEFT JOIN usuarios u2 ON p.jugador2_id = u2.id
        WHERE i.categoria_id = ? AND i.validado = 1
        ORDER BY i.es_semilla DESC, (CASE WHEN i.nro_siembra IS NULL OR i.nro_siembra = 0 THEN 999 ELSE i.nro_siembra END) ASC, i.id ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $categoria_id);
$stmt->execute();
$res = $stmt->get_result();

$semillas = [];
$noSemillas = [];

while ($row = $res->fetch_assoc()) {
    if ((int)$row['es_semilla'] === 1) {
        $semillas[] = $row;
    } else {
        $noSemillas[] = $row;
    }
}

$totalParejas = count($semillas) + count($noSemillas);

if ($totalParejas < 2) {
    http_response_code(400);
    echo json_encode(["error" => "Se requieren al menos 2 parejas validadas para generar el cuadro."]);
    exit;
}

// 2. Determinar tamaño de la llave y nivel inicial
// Potencias de 2: 2, 4, 8, 16, 32
// nivel 1: 2 parejas (1 partido) -> Final
// nivel 2: 4 parejas (2 partidos) -> Semis
// nivel 3: 8 parejas (4 partidos) -> Cuartos
// nivel 4: 16 parejas (8 partidos) -> Octavos
// nivel 5: 32 parejas (16 partidos) -> Dieciseisavos
$startLevel = 1;
$bracketSize = 2;

if ($totalParejas > 16) {
    $startLevel = 5; // Dieciseisavos
    $bracketSize = 32;
} else if ($totalParejas > 8) {
    $startLevel = 4; // Octavos
    $bracketSize = 16;
} else if ($totalParejas > 4) {
    $startLevel = 3; // Cuartos
    $bracketSize = 8;
} else if ($totalParejas > 2) {
    $startLevel = 2; // Semifinales
    $bracketSize = 4;
} else {
    $startLevel = 1; // Final
    $bracketSize = 2;
}

// 3. Limpiar cuadro previo
$conn->query("DELETE FROM torneo_partidos_cuadro WHERE categoria_id = $categoria_id");

// 4. Crear estructura del bracket hacia atrás desde la Final
$bracket_ids = [];

// FINAL (Nivel 1)
$conn->query("INSERT INTO torneo_partidos_cuadro (categoria_id, nivel, lado_cuadro, posicion) VALUES ($categoria_id, 1, 'izq', 0)");
$bracket_ids[1] = [$conn->insert_id];

for ($l = 2; $l <= $startLevel; $l++) {
    $numMatches = pow(2, $l - 1);
    $bracket_ids[$l] = [];
    for ($i = 0; $i < $numMatches; $i++) {
        $lado = ($i < $numMatches / 2) ? 'izq' : 'der';
        $parentIdx = floor($i / 2);
        if ($l - 1 === 1) $parentIdx = 0;
        $next_id = $bracket_ids[$l - 1][$parentIdx];

        $sqlI = "INSERT INTO torneo_partidos_cuadro (categoria_id, nivel, lado_cuadro, posicion, siguiente_partido_id) 
                 VALUES ($categoria_id, $l, '$lado', $i, $next_id)";
        $conn->query($sqlI);
        $bracket_ids[$l][] = $conn->insert_id;
    }
}

// 5. Asignación de Posiciones (Seeding Oficial FIP/ITF)
// Total slots = $bracketSize (2 slots por cada partido de la ronda inicial)
$numInitialMatches = pow(2, $startLevel - 1);
$slots = array_fill(0, $bracketSize, null);

// Orden estándar de siembra para cabezas de serie en slots 0..(bracketSize-1):
// Para 4: CS1 -> 0, CS2 -> 3, CS3 -> 1, CS4 -> 2
// Para 8: CS1 -> 0, CS2 -> 7, CS3 -> 4, CS4 -> 3
// Para 16: CS1 -> 0, CS2 -> 15, CS3 -> 8, CS4 -> 7, CS5 -> 4, CS6 -> 11, CS7 -> 12, CS8 -> 3
// Para 32: CS1 -> 0, CS2 -> 31, CS3 -> 16, CS4 -> 15, CS5 -> 8, CS6 -> 23, CS7 -> 24, CS8 -> 7
$seedOrderMap = [
    2 => [0, 1],
    4 => [0, 3, 1, 2],
    8 => [0, 7, 4, 3, 2, 5, 6, 1],
    16 => [0, 15, 8, 7, 4, 11, 12, 3, 2, 13, 10, 5, 6, 9, 14, 1],
    32 => [0, 31, 16, 15, 8, 23, 24, 7, 4, 27, 20, 11, 12, 19, 28, 3, 2, 29, 18, 13, 10, 21, 26, 5, 6, 25, 22, 9, 14, 17, 30, 1]
];

$order = $seedOrderMap[$bracketSize] ?? range(0, $bracketSize - 1);

// Ubicar semillas en los slots reservados
foreach ($semillas as $sIdx => $s) {
    if (isset($order[$sIdx])) {
        $slots[$order[$sIdx]] = $s['pareja_id'];
    }
}

// Mezclar parejas normales y rellenar los slots vacíos restantes
shuffle($noSemillas);
$noSeedIdx = 0;
for ($s = 0; $s < $bracketSize; $s++) {
    if ($slots[$s] === null && $noSeedIdx < count($noSemillas)) {
        $slots[$s] = $noSemillas[$noSeedIdx]['pareja_id'];
        $noSeedIdx++;
    }
}

// 6. Poblar partidos de la primera ronda
for ($m = 0; $m < $numInitialMatches; $m++) {
    $p1 = $slots[$m * 2];
    $p2 = $slots[$m * 2 + 1];
    $matchId = $bracket_ids[$startLevel][$m];

    if ($p1 || $p2) {
        $sqlU = "UPDATE torneo_partidos_cuadro SET pareja1_id = ?, pareja2_id = ? WHERE id = ?";
        $stU = $conn->prepare($sqlU);
        $stU->bind_param("iii", $p1, $p2, $matchId);
        $stU->execute();
    }
}

// 7. Actualizar estado de la categoría
$conn->query("UPDATE torneo_categorias SET estado = 'Playoffs' WHERE id = $categoria_id");

$nombres_niveles = [5 => "Dieciseisavos", 4 => "Octavos", 3 => "Cuartos", 2 => "Semifinales", 1 => "Final"];
$ronda_txt = $nombres_niveles[$startLevel] ?? "Playoffs";

echo json_encode([
    "success" => true,
    "mensaje" => "Cuadro de Eliminación Directa generado con éxito desde $ronda_txt ($bracketSize slots para $totalParejas parejas).",
    "ronda_inicial" => $ronda_txt,
    "bracket_size" => $bracketSize,
    "total_parejas" => $totalParejas
]);
?>
