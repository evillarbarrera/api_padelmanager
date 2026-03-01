<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

/**
 * CIERRE DE FASE DE GRUPOS Y GENERACIÓN DE PLAYOFFS
 */

$data = json_decode(file_get_contents("php://input"), true);
$categoria_id = $data['categoria_id'] ?? 0;

if (!$categoria_id) {
    http_response_code(400);
    echo json_encode(["error" => "ID de categoría requerido"]);
    exit;
}

// 1. Obtener todos los grupos de la categoría
$sqlGrupos = "SELECT id, nombre FROM torneo_grupos WHERE categoria_id = ?";
$stmtG = $conn->prepare($sqlGrupos);
$stmtG->bind_param("i", $categoria_id);
$stmtG->execute();
$resGrupos = $stmtG->get_result();

$clasificados = []; // [ ['id' => X, 'pos' => 1, 'grupo' => 'A'], ... ]

while ($grupo = $resGrupos->fetch_assoc()) {
    $grupo_id = $grupo['id'];
    
    // Obtener ranking del grupo (Lógica de desempate simplificada por ahora)
    $sqlRank = "SELECT pareja_id, puntos, sf-sc as dif_sets, gf-gc as dif_games 
                FROM torneo_grupo_parejas 
                WHERE grupo_id = ?
                ORDER BY puntos DESC, dif_sets DESC, dif_games DESC
                LIMIT 2"; // Clasifican los 2 mejores
    
    $stmtR = $conn->prepare($sqlRank);
    $stmtR->bind_param("i", $grupo_id);
    $stmtR->execute();
    $resRank = $stmtR->get_result();
    
    $pos = 1;
    while ($row = $resRank->fetch_assoc()) {
        $clasificados[] = [
            "pareja_id" => $row['pareja_id'],
            "pos" => $pos++,
            "grupo" => $grupo['nombre']
        ];
    }
}

$totalClasificados = count($clasificados);
if ($totalClasificados < 2) {
    http_response_code(400);
    echo json_encode(["error" => "No hay suficientes clasificados para armar Playoffs."]);
    exit;
}

/**
 * 2. Determinar Ronda Inicial de Playoffs
 * 2 parejas -> Final
 * 4 parejas -> Semifinales
 * 8 parejas -> Cuartos
 * 16 parejas -> Octavos
 */
$ronda = "";
if ($totalClasificados <= 2) $ronda = "Final";
else if ($totalClasificados <= 4) $ronda = "Semifinal";
else if ($totalClasificados <= 8) $ronda = "Cuartos";
else $ronda = "Octavos";

// 3. Generar Cruces (Ej: 1ero Grupo A vs 2do Grupo B) - Sorteo simple por ahora
shuffle($clasificados); // Para simplificar el mapeo inicial

$partidosN = floor($totalClasificados / 2);
for ($i = 0; $i < $partidosN; $i++) {
    $p1 = $clasificados[$i * 2]['pareja_id'];
    $p2 = $clasificados[($i * 2) + 1]['pareja_id'];
    
    $sqlIns = "INSERT INTO torneo_partidos_v2 (categoria_id, ronda, pareja1_id, pareja2_id, estado) 
               VALUES (?, ?, ?, ?, 'Pendiente')";
    $stmtI = $conn->prepare($sqlIns);
    $stmtI->bind_param("isii", $categoria_id, $ronda, $p1, $p2);
    $stmtI->execute();
}

// 4. Actualizar estado del torneo si es necesario o marcar fase de grupos como 'Cerrada'
// Por ahora solo confirmar éxito
echo json_encode([
    "success" => true, 
    "mensaje" => "Fase de grupos cerrada. Se han generado los partidos de $ronda.",
    "clasificados_count" => $totalClasificados
]);
?>
