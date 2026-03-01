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
require_once "match_utils.php";

$data = json_decode(file_get_contents("php://input"), true);
$torneo_id = $data['torneo_id'] ?? 0;

if (!$torneo_id) {
    http_response_code(400);
    echo json_encode(["error" => "torneo_id es requerido"]);
    exit;
}

// 1. Obtener partidos de Semifinales
$sql = "SELECT * FROM torneo_partidos WHERE torneo_id = ? AND fase = 'Semifinales' AND finalizado = 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $torneo_id);
$stmt->execute();
$matches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

if (count($matches) < 4) {
    // Si hay menos de 4 partidos finalizados, puede que no estén listos
    // Esperamos 2 partidos Oro y 2 partidos Plata.
    http_response_code(400);
    echo json_encode(["error" => "Deben finalizar todos los partidos de Semifinales (4 partidos) antes de generar Finales."]);
    exit;
}

// Helper to find winner pair info
function getWinnerInfo($match, $conn) {
    // Determinar ganador
    if ($match['puntos_t1'] > $match['puntos_t2']) {
        return [
            'jugador_id' => $match['jugador1_id'],
            'jugador2_id' => $match['jugador2_id'],
            'nombre_externo_1' => $match['nombre_externo_1'],
            'nombre_externo_2' => $match['nombre_externo_2'],
            // Fetch names for display if needed
            'pair_id' => findPairId($conn, $match['torneo_id'], $match['jugador1_id'], $match['jugador2_id'], $match['nombre_externo_1'], $match['nombre_externo_2'])
        ];
    } else {
        return [
             'jugador_id' => $match['jugador3_id'],
            'jugador2_id' => $match['jugador4_id'],
            'nombre_externo_1' => $match['nombre_externo_3'],
            'nombre_externo_2' => $match['nombre_externo_4'],
             'pair_id' => findPairId($conn, $match['torneo_id'], $match['jugador3_id'], $match['jugador4_id'], $match['nombre_externo_3'], $match['nombre_externo_4'])
        ];
    }
}

// Re-use findPairId logic or include it. Better include existing file if it had it, but define it here safely.
if (!function_exists('findPairId')) {
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
}

$winnersOro = [];
$winnersPlata = [];

foreach ($matches as $m) {
    if ($m['grupo_id'] === 'Oro') {
        $winnersOro[] = getWinnerInfo($m, $conn);
    } elseif ($m['grupo_id'] === 'Plata') {
        $winnersPlata[] = getWinnerInfo($m, $conn);
    }
}

if (count($winnersOro) < 2 || count($winnersPlata) < 2) {
    http_response_code(400);
    echo json_encode(["error" => "No se encontraron suficientes ganadores en Oro o Plata."]);
    exit;
}

// Generar FINALES
$sqlTorneo = "SELECT * FROM torneos_americanos WHERE id = $torneo_id";
$torneo = $conn->query($sqlTorneo)->fetch_assoc();

// Hora = Último partido semifinal + duración. 
// Para simplificar, buscamos la hora max de inicio de semis y sumamos duración.
$lastTime = strtotime($matches[0]['hora_inicio']); // Base
$dur = $torneo['tiempo_por_partido'] ?: 20;

$hFinal = date('H:i:s', $lastTime + ($dur * 60)); 
$fecha = $torneo['fecha'];
$rFinal = 9; // Ronda 9 por ejemplo

// Borrar Finales previas si existen
$conn->query("DELETE FROM torneo_partidos WHERE torneo_id = $torneo_id AND fase = 'Finales'");

// FINAL ORO
insertMatch($conn, $torneo_id, $rFinal, 1, $hFinal, $winnersOro[0], $winnersOro[1], 'Oro', 'Finales', null, $fecha, date('H:i:s', strtotime($hFinal) + ($dur * 60)));

// FINAL PLATA
insertMatch($conn, $torneo_id, $rFinal, 2, $hFinal, $winnersPlata[0], $winnersPlata[1], 'Plata', 'Finales', null, $fecha, date('H:i:s', strtotime($hFinal) + ($dur * 60)));

echo json_encode(["success" => true, "message" => "Finales generadas. Ve a la pestaña 'Finales'."]);
