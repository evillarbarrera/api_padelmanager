<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$categoria_id = $data['categoria_id'] ?? 0;

if (!$categoria_id) {
    http_response_code(400);
    echo json_encode(["error" => "Categoria ID es requerido"]);
    exit;
}

// 1. Obtener parejas validadas para esta categoría
$sql = "SELECT i.pareja_id FROM torneo_inscripciones i 
        WHERE i.categoria_id = ? AND i.validado = 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $categoria_id);
$stmt->execute();
$result = $stmt->get_result();

$parejas = [];
while ($row = $result->fetch_assoc()) {
    $parejas[] = $row['pareja_id'];
}

$totalParejas = count($parejas);
if ($totalParejas < 3) {
    http_response_code(400);
    echo json_encode(["error" => "Se necesitan al menos 3 parejas validadas para generar grupos. Actuales: $totalParejas"]);
    exit;
}

// 2. Limpiar grupos previos si existen (Opcional, precaución)
$conn->query("DELETE FROM torneo_grupos WHERE categoria_id = $categoria_id");

// 3. Determinar distribución de grupos (Priorizar 4 parejas por grupo, min 3)
// Ejemplo: 12 parejas -> 3 grupos de 4. 10 parejas -> 2 grupos de 3 y 1 de 4.
shuffle($parejas); // Aleatoriedad para el sorteo

$numGrupos = floor($totalParejas / 4);
if ($totalParejas % 4 != 0 && $numGrupos == 0) $numGrupos = 1;

// Ajuste si sobran muchas para hacer grupos de 3
if ($totalParejas % 4 != 0 && $totalParejas > 4) {
    // Si sobran 2, mejor hacer grupos de 3 y 4
    // Por ahora lógica simple:
}

$grupos = array_chunk($parejas, 4); 
// Si el último grupo tiene 1 o 2, lo repartimos en los anteriores o lo dejamos de 3 si es posible
if (count($grupos) > 1) {
    $ultimo = end($grupos);
    if (count($ultimo) < 3) {
        array_pop($grupos);
        $i = 0;
        foreach ($ultimo as $p_id) {
            $grupos[$i % count($grupos)][] = $p_id;
            $i++;
        }
    }
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
