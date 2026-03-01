<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$partido_id = $data['match_id'] ?? ($data['partido_id'] ?? 0);
$puntos_t1 = $data['puntos_t1'] ?? null;
$puntos_t2 = $data['puntos_t2'] ?? null;
$resultado = $data['resultado'] ?? []; // [{p1: 6, p2: 4}, {p1: 6, p2: 2}]

if (!$partido_id) {
    http_response_code(400);
    echo json_encode(["error" => "ID de partido requerido"]);
    exit;
}

// 1. Intentar encontrar en torneo_partidos_v2 primero
$sqlP2 = "SHOW TABLES LIKE 'torneo_partidos_v2'";
$hasV2 = $conn->query($sqlP2)->num_rows > 0;
$partido = null;
$version = 1;

if ($hasV2) {
    $sqlCheck = "SELECT * FROM torneo_partidos_v2 WHERE id = ?";
    $stmtC = $conn->prepare($sqlCheck);
    $stmtC->bind_param("i", $partido_id);
    $stmtC->execute();
    $partido = $stmtC->get_result()->fetch_assoc();
    if ($partido) $version = 2;
}

// 2. Si no es V2, buscar en torneo_partidos (v1)
if (!$partido) {
    $sqlCheck = "SELECT * FROM torneo_partidos WHERE id = ?";
    $stmtC = $conn->prepare($sqlCheck);
    $stmtC->bind_param("i", $partido_id);
    $stmtC->execute();
    $partido = $stmtC->get_result()->fetch_assoc();
    if ($partido) $version = 1;
}

if (!$partido) {
    http_response_code(404);
    echo json_encode(["error" => "Partido no encontrado en ninguna tabla"]);
    exit;
}

if ($version === 2) {
    // --- LÓGICA V2 (Sets y Grupos V2) ---
    if (empty($resultado)) {
        // Fallback si mandan puntos sueltos a un V2
        if ($puntos_t1 !== null && $puntos_t2 !== null) {
            $resultado = [['p1' => $puntos_t1, 'p2' => $puntos_t2]];
        } else {
            http_response_code(400);
            echo json_encode(["error" => "Resultado (sets) requerido para torneo V2"]);
            exit;
        }
    }

    $sets_p1 = 0; $sets_p2 = 0; $g1_total = 0; $g2_total = 0;
    foreach ($resultado as $set) {
        $p1 = (int)$set['p1']; $p2 = (int)$set['p2'];
        $g1_total += $p1; $g2_total += $p2;
        if ($p1 > $p2) $sets_p1++; else if ($p2 > $p1) $sets_p2++;
    }
    $ganador_id = ($sets_p1 > $sets_p2) ? $partido['pareja1_id'] : $partido['pareja2_id'];
    $resultado_json = json_encode($resultado);

    $sqlUpd = "UPDATE torneo_partidos_v2 SET ganador_id = ?, resultado_json = ?, estado = 'Finalizado' WHERE id = ?";
    $stmtUpd = $conn->prepare($sqlUpd);
    $stmtUpd->bind_param("isi", $ganador_id, $resultado_json, $partido_id);
    $stmtUpd->execute();

    // Actualizar tabla de posiciones V2 si aplica
    if (!empty($partido['grupo_id'])) {
        updatePosicionesV2($conn, $partido, $ganador_id, $sets_p1, $sets_p2, $g1_total, $g2_total);
    }

} else {
    // --- LÓGICA V1 (Americano Estándar) ---
    if ($puntos_t1 === null || $puntos_t2 === null) {
        // Fallback si mandan resultado array a un V1
        if (!empty($resultado)) {
            $puntos_t1 = (int)$resultado[0]['p1'];
            $puntos_t2 = (int)$resultado[0]['p2'];
        } else {
            http_response_code(400);
            echo json_encode(["error" => "Puntos t1 y t2 requeridos para Americano"]);
            exit;
        }
    }

    // Asegurar que las columnas existen (Compatibilidad MySQL antiguo)
    function ensureColumn($conn, $table, $column, $definition) {
        $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if ($res && $res->num_rows == 0) {
            $conn->query("ALTER TABLE `$table` ADD `$column` $definition");
        }
    }

    ensureColumn($conn, 'torneo_partidos', 'puntos_t1', "INT DEFAULT 0");
    ensureColumn($conn, 'torneo_partidos', 'puntos_t2', "INT DEFAULT 0");
    ensureColumn($conn, 'torneo_partidos', 'finalizado', "TINYINT DEFAULT 0");

    // Actualizar el partido (Permitimos sobreescribir resultados si hubo un error)
    $sqlUpd = "UPDATE torneo_partidos SET puntos_t1 = ?, puntos_t2 = ?, finalizado = 1 WHERE id = ?";
    $stmtUpd = $conn->prepare($sqlUpd);
    $stmtUpd->bind_param("iii", $puntos_t1, $puntos_t2, $partido_id);
    $stmtUpd->execute();
}

function updatePosicionesV2($conn, $partido, $ganador_id, $s1, $s2, $g1, $g2) {
    $grupo_id = $partido['grupo_id'];
    $p1_id = $partido['pareja1_id'];
    $p2_id = $partido['pareja2_id'];
    $pts1 = ($ganador_id == $p1_id) ? 3 : 1;
    $pts2 = ($ganador_id == $p2_id) ? 3 : 1;
    $win1 = ($ganador_id == $p1_id) ? 1 : 0;
    $win2 = ($ganador_id == $p2_id) ? 1 : 0;

    $sql = "UPDATE torneo_grupo_parejas 
            SET puntos = puntos + ?, pj = pj + 1, pg = pg + ?, pp = pp + ?, 
                sf = sf + ?, sc = sc + ?, gf = gf + ?, gc = gc + ?
            WHERE grupo_id = ? AND pareja_id = ?";
    
    $st1 = $conn->prepare($sql);
    $pp1 = ($win1 == 0) ? 1 : 0;
    $st1->bind_param("iiiiiiiii", $pts1, $win1, $pp1, $s1, $s2, $g1, $g2, $grupo_id, $p1_id);
    $st1->execute();

    $st2 = $conn->prepare($sql);
    $pp2 = ($win2 == 0) ? 1 : 0;
    $st2->bind_param("iiiiiiiii", $pts2, $win2, $pp2, $s2, $s1, $g2, $g1, $grupo_id, $p2_id);
    $st2->execute();
}

echo json_encode(["success" => true, "mensaje" => "Resultado guardado correctamente"]);
?>
