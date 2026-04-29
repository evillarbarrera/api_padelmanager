<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$categoria_id = $data['categoria_id'] ?? 0;

if (!$categoria_id) {
    http_response_code(400);
    echo json_encode(["error" => "Categoria ID es requerido"]);
    exit;
}

// 1. Obtener inscripciones validadas con info de siembra
$sql = "SELECT i.pareja_id, i.es_semilla, i.nro_siembra FROM torneo_inscripciones i 
        WHERE i.categoria_id = ? AND i.validado = 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $categoria_id);
$stmt->execute();
$result = $stmt->get_result();

$semillas = [];
$normales = [];

while ($row = $result->fetch_assoc()) {
    if ($row['es_semilla'] == 1) {
        $semillas[] = $row;
    } else {
        $normales[] = $row['pareja_id'];
    }
}

$totalParejas = count($semillas) + count($normales);
if ($totalParejas < 3) {
    http_response_code(400);
    echo json_encode(["error" => "Se necesitan al menos 3 parejas validadas para generar los grupos."]);
    exit;
}

// Ordenar semillas por número de siembra (1, 2, 3...)
usort($semillas, function($a, $b) {
    return ($a['nro_siembra'] ?: 99) - ($b['nro_siembra'] ?: 99);
});

// 2. Determinar número de grupos (Priorizar 4 parejas por grupo)
$numGrupos = floor($totalParejas / 3); // Intentamos grupos de 3 o 4
if ($totalParejas % 4 == 0) $numGrupos = $totalParejas / 4;
else if ($totalParejas <= 5) $numGrupos = 1;
else if ($totalParejas <= 8 && $totalParejas >= 6) $numGrupos = 2;

$grupos = array_fill(0, $numGrupos, []);

// 3. Distribución de Semillas (Sorteo dirigido o Serpentina)
shuffle($normales); 
$currentGrupo = 0;

// Posicionar semillas en grupos distintos
foreach ($semillas as $s) {
    $grupos[$currentGrupo % $numGrupos][] = $s['pareja_id'];
    $currentGrupo++;
}

// Rellenar el resto de forma equilibrada
foreach ($normales as $p_id) {
    // Buscar el grupo con menos integrantes para equilibrar
    $target = 0;
    $minSize = 999;
    foreach ($grupos as $idx => $g) {
        if (count($g) < $minSize) {
            $minSize = count($g);
            $target = $idx;
        }
    }
    $grupos[$target][] = $p_id;
}

// 4. Crear los grupos en la DB
$letras = range('A', 'Z');
foreach ($grupos as $index => $integrantes) {
    $nombreGrupo = "Grupo " . $letras[$index];
    $stmtG = $conn->prepare("INSERT INTO torneo_grupos (categoria_id, nombre) VALUES (?, ?)");
    $stmtG->bind_param("is", $categoria_id, $nombreGrupo);
    $stmtG->execute();
    $grupo_id = $conn->insert_id;

    foreach ($integrantes as $p_id) {
        $stmtP = $conn->prepare("INSERT INTO torneo_grupo_parejas (grupo_id, pareja_id) VALUES (?, ?)");
        $stmtP->bind_param("ii", $grupo_id, $p_id);
        $stmtP->execute();
        
        // También podríamos generar los partidos del Round Robin aquí
        generarCalendarioGrupo($conn, $grupo_id, $categoria_id, $integrantes);
    }
}

function generarCalendarioGrupo($conn, $grupo_id, $cat_id, $parejas) {
    $n = count($parejas);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $p1 = $parejas[$i];
            $p2 = $parejas[$j];
            $sql = "INSERT INTO torneo_partidos_v2 (categoria_id, grupo_id, pareja1_id, pareja2_id, estado) 
                    VALUES (?, ?, ?, ?, 'Pendiente')";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iiii", $cat_id, $grupo_id, $p1, $p2);
            $stmt->execute();
        }
    }
}

echo json_encode([
    "success" => true, 
    "mensaje" => "Se han generado " . count($grupos) . " grupos para $totalParejas parejas.",
    "detalles" => array_map('count', $grupos)
]);
?>
