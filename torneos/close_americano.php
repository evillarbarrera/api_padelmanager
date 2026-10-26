<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../auth/auth_helper.php";
$userId = validateToken();
if (!$userId) {
    sendUnauthorized();
}

require_once "../db.php";

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true) ?: [];

$torneo_id = (int)($data['torneo_id'] ?? $data['id'] ?? $_POST['torneo_id'] ?? $_POST['id'] ?? $_GET['torneo_id'] ?? $_GET['id'] ?? 0);

if (!$torneo_id) {
    http_response_code(400);
    echo json_encode(["error" => "ID de torneo requerido"]);
    exit;
}

/**
 * SILENT SCHEMA FIX
 */
function ensureColumn($conn, $table, $column, $definition) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($check && $check->num_rows == 0) {
        @$conn->query("ALTER TABLE `$table` ADD `$column` $definition");
    }
}

ensureColumn($conn, 'usuarios', 'puntos_ranking', "INT DEFAULT 0");
ensureColumn($conn, 'torneos_americanos', 'estado', "VARCHAR(50) DEFAULT 'Abierto'");
@$conn->query("ALTER TABLE torneos_americanos MODIFY COLUMN estado VARCHAR(50) DEFAULT 'Abierto'");
ensureColumn($conn, 'torneos_americanos', 'puntos_1er_lugar', "INT DEFAULT 100");
ensureColumn($conn, 'torneos_americanos', 'puntos_2do_lugar', "INT DEFAULT 60");
ensureColumn($conn, 'torneos_americanos', 'puntos_3er_lugar', "INT DEFAULT 40");
ensureColumn($conn, 'torneos_americanos', 'puntos_4to_lugar', "INT DEFAULT 20");
ensureColumn($conn, 'torneos_americanos', 'puntos_participacion', "INT DEFAULT 5");
ensureColumn($conn, 'torneos_americanos', 'categoria', "VARCHAR(50) DEFAULT 'Cuarta'");
@$conn->query("CREATE TABLE IF NOT EXISTS ranking_categorias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    categoria VARCHAR(50) NOT NULL,
    puntos INT DEFAULT 0,
    UNIQUE KEY (usuario_id, categoria)
)");
ensureColumn($conn, 'torneo_partidos', 'finalizado', "TINYINT DEFAULT 0");
ensureColumn($conn, 'torneo_partidos', 'puntos_t1', "INT DEFAULT 0");
ensureColumn($conn, 'torneo_partidos', 'puntos_t2', "INT DEFAULT 0");

if (!function_exists('asignarPuntosAmericano')) {
    function asignarPuntosAmericano($conn, $jugadores, $puntos, $cat) {
        if (!$jugadores || $puntos <= 0) return;
        foreach ($jugadores as $uid) {
            $uid = (int)$uid;
            if ($uid > 0) {
                try {
                    // Update global
                    $conn->query("UPDATE usuarios SET puntos_ranking = puntos_ranking + $puntos WHERE id = $uid");
                } catch (Throwable $e) {}

                try {
                    // Update categoria
                    $stmt = $conn->prepare("INSERT INTO ranking_categorias (usuario_id, categoria, puntos) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE puntos = puntos + VALUES(puntos)");
                    if ($stmt) {
                        $stmt->bind_param("isi", $uid, $cat, $puntos);
                        $stmt->execute();
                        $stmt->close();
                    }
                } catch (Throwable $e) {}
            }
        }
    }
}

/**
 * START CLOSING PROCESS
 */
try {
    $conn->begin_transaction();

    // 1. Obtener datos del torneo
    $sqlTorneo = "SELECT * FROM torneos_americanos WHERE id = ?";
    $stmtT = $conn->prepare($sqlTorneo);
    $stmtT->bind_param("i", $torneo_id);
    $stmtT->execute();
    $torneo = $stmtT->get_result()->fetch_assoc();
    $stmtT->close();

    if (!$torneo) {
        throw new Exception("Torneo no encontrado");
    }

    $estadoActual = strtolower(trim($torneo['estado'] ?? ''));
    if ($estadoActual === 'cerrado') {
        $conn->commit();
        echo json_encode(["success" => true, "mensaje" => "El torneo ya estaba cerrado"]);
        exit;
    }

    $cat = !empty($torneo['categoria']) ? $torneo['categoria'] : 'Cuarta';

    // 2. Obtener participantes y crear mapa para ranking
    $sqlPart = "SELECT * FROM torneo_participantes WHERE torneo_id = ?";
    $stmtP = $conn->prepare($sqlPart);
    $stmtP->bind_param("i", $torneo_id);
    $stmtP->execute();
    $resP = $stmtP->get_result();

    $participantes = [];
    $playerMap = [];
    $nameMap = [];
    
    $i = 0;
    while ($row = $resP->fetch_assoc()) {
        $row['ganados'] = 0;
        $row['dif'] = 0;
        $participantes[$i] = $row;
        
        if (!empty($row['jugador_id'])) $playerMap['id_' . $row['jugador_id']] = $i;
        if (!empty($row['jugador2_id'])) $playerMap['id_' . $row['jugador2_id']] = $i;
        
        if (!empty($row['nombre_externo_1'])) $nameMap[strtolower(trim($row['nombre_externo_1']))] = $i;
        if (!empty($row['nombre_externo_2'])) $nameMap[strtolower(trim($row['nombre_externo_2']))] = $i;
        
        $i++;
    }
    $stmtP->close();

    // 3. Procesar partidos finalizados para el ranking (si los hubiera)
    $sqlM = "SELECT * FROM torneo_partidos WHERE torneo_id = ? AND finalizado = 1";
    $stmtM = $conn->prepare($sqlM);
    $stmtM->bind_param("i", $torneo_id);
    $stmtM->execute();
    $resM = $stmtM->get_result();

    while ($m = $resM->fetch_assoc()) {
        $idx1 = -1; 
        $idx2 = -1;

        if (!empty($m['jugador1_id']) && isset($playerMap['id_' . $m['jugador1_id']])) {
            $idx1 = $playerMap['id_' . $m['jugador1_id']];
        } elseif (!empty($m['nombre_externo_1']) && isset($nameMap[strtolower(trim($m['nombre_externo_1']))])) {
            $idx1 = $nameMap[strtolower(trim($m['nombre_externo_1']))];
        }

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
    $stmtM->close();

    // 4. Calcular posiciones finales basadas en FINALES (si existen)
    $sqlFinals = "SELECT * FROM torneo_partidos WHERE torneo_id = ? AND fase = 'Finales' AND finalizado = 1";
    $stmtF = $conn->prepare($sqlFinals);
    $stmtF->bind_param("i", $torneo_id);
    $stmtF->execute();
    $resF = $stmtF->get_result();

    $posiciones = [];

    while ($f = $resF->fetch_assoc()) {
        $ganador = ($f['puntos_t1'] > $f['puntos_t2']) ? 1 : 2;
        
        $p1_jug1 = $f['jugador1_id'];
        $p1_jug2 = $f['jugador2_id'];
        $p2_jug1 = $f['jugador3_id'];
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
    $stmtF->close();

    // 5. Asignar puntos según esquema configurado
    $p_oro_1 = (int)($torneo['puntos_1er_lugar'] ?? 100);
    $p_oro_2 = (int)($torneo['puntos_2do_lugar'] ?? 60);
    $p_plata_1 = (int)($torneo['puntos_3er_lugar'] ?? 40);
    $p_plata_2 = (int)($torneo['puntos_4to_lugar'] ?? 20);
    $p_part = (int)($torneo['puntos_participacion'] ?? 5);

    // Puntos de participación a TODOS los inscritos
    foreach ($participantes as $p) {
        asignarPuntosAmericano($conn, [$p['jugador_id'], $p['jugador2_id']], $p_part, $cat);
    }

    // Puntos extra por podio
    if (isset($posiciones['oro_1'])) asignarPuntosAmericano($conn, $posiciones['oro_1'], $p_oro_1, $cat);
    if (isset($posiciones['oro_2'])) asignarPuntosAmericano($conn, $posiciones['oro_2'], $p_oro_2, $cat);
    if (isset($posiciones['plata_1'])) asignarPuntosAmericano($conn, $posiciones['plata_1'], $p_plata_1, $cat);
    if (isset($posiciones['plata_2'])) asignarPuntosAmericano($conn, $posiciones['plata_2'], $p_plata_2, $cat);

    // 6. Marcar como cerrado
    $sqlClose = "UPDATE torneos_americanos SET estado = 'Cerrado' WHERE id = ?";
    $stClose = $conn->prepare($sqlClose);
    if (!$stClose) throw new Exception("Error preparando cierre: " . $conn->error);
    $stClose->bind_param("i", $torneo_id);
    if (!$stClose->execute()) {
        throw new Exception("Error al actualizar estado: " . $conn->error);
    }
    $stClose->close();
    
    $conn->commit();
    echo json_encode(["success" => true, "mensaje" => "Torneo cerrado exitosamente. Puntos distribuidos en categoría " . $cat]);

} catch (Throwable $e) {
    if (isset($conn)) @$conn->rollback();
    http_response_code(500);
    echo json_encode(["status" => "error", "error" => $e->getMessage()]);
}
