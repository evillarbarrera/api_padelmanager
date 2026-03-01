<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Compatibilidad para obtener el Authorization Header
$authHeader = '';
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
} elseif (function_exists('getallheaders')) {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? ($headers['authorization'] ?? '');
}

if ($authHeader !== 'Bearer ' . base64_encode("1|padel_academy")) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$torneo_id = $data['torneo_id'] ?? 0;

if (!$torneo_id) {
    echo json_encode(["error" => "ID de torneo requerido"]);
    exit;
}

/**
 * SILENT SCHEMA FIX
 */
function ensureColumn($conn, $table, $column, $definition) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($check && $check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD `$column` $definition");
    }
}

ensureColumn($conn, 'usuarios', 'puntos_ranking', "INT DEFAULT 0");
ensureColumn($conn, 'torneos_americanos', 'estado', "ENUM('Abierto', 'Cerrado') DEFAULT 'Abierto'");
ensureColumn($conn, 'torneos_americanos', 'puntos_1er_lugar', "INT DEFAULT 100");
ensureColumn($conn, 'torneos_americanos', 'puntos_2do_lugar', "INT DEFAULT 60");
ensureColumn($conn, 'torneos_americanos', 'puntos_3er_lugar', "INT DEFAULT 40");
ensureColumn($conn, 'torneos_americanos', 'puntos_4to_lugar', "INT DEFAULT 20");
ensureColumn($conn, 'torneos_americanos', 'puntos_participacion', "INT DEFAULT 5");
ensureColumn($conn, 'torneos_americanos', 'categoria', "VARCHAR(50) DEFAULT 'Cuarta'");
$conn->query("CREATE TABLE IF NOT EXISTS ranking_categorias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    categoria VARCHAR(50) NOT NULL,
    puntos INT DEFAULT 0,
    UNIQUE KEY (usuario_id, categoria)
)");
ensureColumn($conn, 'torneo_partidos', 'finalizado', "TINYINT DEFAULT 0");
ensureColumn($conn, 'torneo_partidos', 'puntos_t1', "INT DEFAULT 0");
ensureColumn($conn, 'torneo_partidos', 'puntos_t2', "INT DEFAULT 0");

/**
 * START CLOSING PROCESS
 */
try {
    // 1. Obtener datos del torneo
    $sqlTorneo = "SELECT * FROM torneos_americanos WHERE id = ?";
    $stmtT = $conn->prepare($sqlTorneo);
    $stmtT->bind_param("i", $torneo_id);
    $stmtT->execute();
    $torneo = $stmtT->get_result()->fetch_assoc();

    if (!$torneo) {
        throw new Exception("Torneo no encontrado");
    }

    if (($torneo['estado'] ?? 'Abierto') === 'Cerrado') {
        echo json_encode(["success" => true, "mensaje" => "El torneo ya estaba cerrado"]);
        exit;
    }

    // 2. Obtener participantes y crear mapa para ranking
    $sqlPart = "SELECT * FROM torneo_participantes WHERE torneo_id = ?";
    $stmtP = $conn->prepare($sqlPart);
    $stmtP->bind_param("i", $torneo_id);
    $stmtP->execute();
    $resP = $stmtP->get_result();

    $participantes = [];
    $playerMap = []; // Map player ID to participant list index
    $nameMap = [];   // Map external names to participant list index (for manual players)
    
    $i = 0;
    while ($row = $resP->fetch_assoc()) {
        $row['ganados'] = 0;
        $row['dif'] = 0;
        $participantes[$i] = $row;
        
        // Identificar por ID si existe
        if (!empty($row['jugador_id'])) $playerMap['id_' . $row['jugador_id']] = $i;
        if (!empty($row['jugador2_id'])) $playerMap['id_' . $row['jugador2_id']] = $i;
        
        // Identificar por nombres (para manuales o fallback)
        if (!empty($row['nombre_externo_1'])) $nameMap[strtolower(trim($row['nombre_externo_1']))] = $i;
        if (!empty($row['nombre_externo_2'])) $nameMap[strtolower(trim($row['nombre_externo_2']))] = $i;
        
        $i++;
    }

    // 3. Procesar partidos finalizados para el ranking
    $sqlM = "SELECT * FROM torneo_partidos WHERE torneo_id = ? AND finalizado = 1";
    $stmtM = $conn->prepare($sqlM);
    $stmtM->bind_param("i", $torneo_id);
    $stmtM->execute();
    $resM = $stmtM->get_result();

    while ($m = $resM->fetch_assoc()) {
        $idx1 = -1; 
        $idx2 = -1;

        // Intentar encontrar Pareja 1 (Jugadores 1 y 2)
        if (!empty($m['jugador1_id']) && isset($playerMap['id_' . $m['jugador1_id']])) {
            $idx1 = $playerMap['id_' . $m['jugador1_id']];
        } elseif (!empty($m['nombre_externo_1']) && isset($nameMap[strtolower(trim($m['nombre_externo_1']))])) {
            $idx1 = $nameMap[strtolower(trim($m['nombre_externo_1']))];
        }

        // Intentar encontrar Pareja 2 (Jugadores 3 y 4)
        if (!empty($m['jugador3_id']) && isset($playerMap['id_' . $m['jugador3_id']])) {
            $idx2 = $playerMap['id_' . $m['jugador3_id']];
        } elseif (!empty($m['nombre_externo_3']) && isset($nameMap[strtolower(trim($m['nombre_externo_3']))])) {
            $idx2 = $nameMap[strtolower(trim($m['nombre_externo_3']))];
        }

        if ($idx1 === -1 || $idx2 === -1) continue;
        
        $pt1 = (int)$m['puntos_t1']; 
        $pt2 = (int)$m['puntos_t2'];
        
        $participantes[$idx1]['dif'] += ($pt1 - $pt2);
        $participantes[$idx2]['dif'] += ($pt2 - $pt1);
        
        if ($pt1 > $pt2) $participantes[$idx1]['ganados']++;
        elseif ($pt2 > $pt1) $participantes[$idx2]['ganados']++;
    }

    // 4. Calcular posiciones finales basadas en FINALES (Oro y Plata)
    // Buscamos los partidos de la fase "Finales"
    $sqlFinals = "SELECT * FROM torneo_partidos WHERE torneo_id = ? AND fase = 'Finales' AND finalizado = 1";
    $stmtF = $conn->prepare($sqlFinals);
    $stmtF->bind_param("i", $torneo_id);
    $stmtF->execute();
    $resF = $stmtF->get_result();

    $posiciones = []; // map participant_id => '1_oro', '2_oro', '1_plata', '2_plata'

    while ($f = $resF->fetch_assoc()) {
        $ganador = ($f['puntos_t1'] > $f['puntos_t2']) ? 1 : 2;
        
        // Identificar IDs de participantes (parejas)
        // Esto asume que tenemos el ID de participante, si no, hay que buscarlo por jug1/jug2
        // Simplificación: usaremos los IDs de jugadores para asignar puntos directamente.
        
        $p1_jug1 = $f['jugador1_id'];
        $p1_jug2 = $f['jugador2_id'];
        $p2_jug1 = $f['jugador3_id']; // En DB a veces es jugador3_id/4_id para equipo 2
        $p2_jug2 = $f['jugador4_id'];

        if ($f['grupo_id'] === 'Oro') {
            if ($ganador === 1) {
                $posiciones['oro_1'] = [$p1_jug1, $p1_jug2];
                $posiciones['oro_2'] = [$p2_jug1, $p2_jug2];
            } else {
                $posiciones['oro_1'] = [$p2_jug1, $p2_jug2];
                $posiciones['oro_2'] = [$p1_jug1, $p1_jug2];
            }
        } elseif ($f['grupo_id'] === 'Plata') {
            if ($ganador === 1) {
                $posiciones['plata_1'] = [$p1_jug1, $p1_jug2];
                $posiciones['plata_2'] = [$p2_jug1, $p2_jug2];
            } else {
                $posiciones['plata_1'] = [$p2_jug1, $p2_jug2];
                $posiciones['plata_2'] = [$p1_jug1, $p1_jug2];
            }
        }
    }

    // Función auxiliar
    function asignarPuntos($conn, $jugadores, $puntos, $cat) {
        if (!$jugadores) return;
        foreach ($jugadores as $uid) {
            if ($uid) {
                // Update global
                $conn->query("UPDATE usuarios SET puntos_ranking = puntos_ranking + $puntos WHERE id = $uid");
                // Update categoria
                $stmt = $conn->prepare("INSERT INTO ranking_categorias (usuario_id, categoria, puntos) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE puntos = puntos + VALUES(puntos)");
                $stmt->bind_param("isi", $uid, $cat, $puntos);
                $stmt->execute();
            }
        }
    }

    // Asignar puntos según esquema
    $p_oro_1 = (int)($torneo['puntos_1er_lugar'] ?? 100);
    $p_oro_2 = (int)($torneo['puntos_2do_lugar'] ?? 60);
    $p_plata_1 = (int)($torneo['puntos_3er_lugar'] ?? 40);
    $p_plata_2 = (int)($torneo['puntos_4to_lugar'] ?? 20);
    $p_part = (int)($torneo['puntos_participacion'] ?? 5);

    $cat = $torneo['categoria'] ?? 'Cuarta';

    // Dar puntos de participación a TODOS los inscritos primero
    foreach ($participantes as $p) {
        asignarPuntos($conn, [$p['jugador_id'], $p['jugador2_id']], $p_part, $cat);
    }

    // Dar puntos extra por posición
    if (isset($posiciones['oro_1'])) asignarPuntos($conn, $posiciones['oro_1'], $p_oro_1, $cat);
    if (isset($posiciones['oro_2'])) asignarPuntos($conn, $posiciones['oro_2'], $p_oro_2, $cat);
    if (isset($posiciones['plata_1'])) asignarPuntos($conn, $posiciones['plata_1'], $p_plata_1, $cat);
    if (isset($posiciones['plata_2'])) asignarPuntos($conn, $posiciones['plata_2'], $p_plata_2, $cat);


    // Marcar como cerrado
    $sqlClose = "UPDATE torneos_americanos SET estado = 'Cerrado' WHERE id = ?";
    $stClose = $conn->prepare($sqlClose);
    if (!$stClose) throw new Exception("Error preparando cierre: " . $conn->error);
    $stClose->bind_param("i", $torneo_id);
    if (!$stClose->execute()) {
        throw new Exception("Error al actualizar estado: " . $conn->error);
    }
    
    $conn->commit();
    echo json_encode(["success" => true, "mensaje" => "Torneo cerrado y puntos distribuidos en categoría " . $categoria_torneo]);

} catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    http_response_code(500);
    echo json_encode(["status" => "error", "error" => $e->getMessage()]);
}
?>
