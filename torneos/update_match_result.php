<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$partido_id = $data['match_id'] ?? ($data['partido_id'] ?? 0);
$resultado = $data['resultado'] ?? []; 

if (!$partido_id) {
    echo json_encode(["error" => "ID de partido requerido"]);
    exit;
}

// 1. Identificar Versión del Partido
$partido = null;
$version = 0;

// Buscar en Cuadro V3
$res3 = $conn->query("SELECT * FROM torneo_partidos_cuadro WHERE id = $partido_id");
if ($res3 && $res3->num_rows > 0) {
    $partido = $res3->fetch_assoc();
    $version = 3;
}

// Buscar en V2 si no se encontró
if (!$partido) {
    $res2 = $conn->query("SELECT * FROM torneo_partidos_v2 WHERE id = $partido_id");
    if ($res2 && $res2->num_rows > 0) {
        $partido = $res2->fetch_assoc();
        $version = 2;
    }
}

// Fallback V1
if (!$partido) {
    $res1 = $conn->query("SELECT * FROM torneo_partidos WHERE id = $partido_id");
    if ($res1 && $res1->num_rows > 0) {
        $partido = $res1->fetch_assoc();
        $version = 1;
    }
}

if (!$partido) {
    echo json_encode(["error" => "Partido no encontrado"]);
    exit;
}

/**
 * PROCESAMIENTO SEGÚN VERSIÓN
 */
if ($version === 3) {
    // LÓGICA V3: BRACKET PROFESIONAL
    $sets_p1 = 0; $sets_p2 = 0;
    foreach ($resultado as $set) {
        if ($set['p1'] > $set['p2']) $sets_p1++; else if ($set['p2'] > $set['p1']) $sets_p2++;
    }
    $ganador_id = ($sets_p1 > $sets_p2) ? $partido['pareja1_id'] : $partido['pareja2_id'];
    $resultado_json = json_encode($resultado);

    $sqlUpd = "UPDATE torneo_partidos_cuadro SET ganador_id = ?, resultado_json = ?, finalizado = 1 WHERE id = ?";
    $stmt = $conn->prepare($sqlUpd);
    $stmt->bind_param("isi", $ganador_id, $resultado_json, $partido_id);
    $stmt->execute();

    // PROGRESIÓN AUTOMÁTICA
    if ($partido['siguiente_partido_id']) {
        $next_id = $partido['siguiente_partido_id'];
        $pos = $partido['posicion'];
        $col = ($pos % 2 === 0) ? 'pareja1_id' : 'pareja2_id';
        
        $conn->query("UPDATE torneo_partidos_cuadro SET $col = $ganador_id WHERE id = $next_id");
    }

} else if ($version === 2) {
    // LÓGICA V2: GRUPOS ROUND ROBIN
    $sets_p1 = 0; $sets_p2 = 0; $g1_total = 0; $g2_total = 0;
    foreach ($resultado as $set) {
        $p1 = (int)$set['p1']; $p2 = (int)$set['p2'];
        $g1_total += $p1; $g2_total += $p2;
        if ($p1 > $p2) $sets_p1++; else if ($p2 > $p1) $sets_p2++;
    }
    $ganador_id = ($sets_p1 > $sets_p2) ? $partido['pareja1_id'] : $partido['pareja2_id'];
    $resultado_json = json_encode($resultado);

    $conn->query("UPDATE torneo_partidos_v2 SET ganador_id = $ganador_id, resultado_json = '$resultado_json', estado = 'Finalizado' WHERE id = $partido_id");

    if (!empty($partido['grupo_id'])) {
        updatePosicionesV2($conn, $partido, $ganador_id, $sets_p1, $sets_p2, $g1_total, $g2_total);
    }
} else {
    // LÓGICA V1: AMERICANO CLÁSICO (Simplificado)
    $p1 = $data['puntos_t1'] ?? $resultado[0]['p1'] ?? 0;
    $p2 = $data['puntos_t2'] ?? $resultado[0]['p2'] ?? 0;
    $conn->query("UPDATE torneo_partidos SET puntos_t1 = $p1, puntos_t2 = $p2, finalizado = 1 WHERE id = $partido_id");
}

function updatePosicionesV2($conn, $partido, $ganador_id, $s1, $s2, $g1, $g2) {
    $grupo_id = $partido['grupo_id'];
    $p1_id = $partido['pareja1_id'];
    $p2_id = $partido['pareja2_id'];
    $win1 = ($ganador_id == $p1_id) ? 1 : 0;
    $win2 = ($ganador_id == $p2_id) ? 1 : 0;
    $pts1 = $win1 ? 3 : 1; $pts2 = $win2 ? 3 : 1;

    $sql = "UPDATE torneo_grupo_parejas 
            SET puntos = puntos + ?, pj = pj + 1, pg = pg + ?, pp = pp + ?, 
                sf = sf + ?, sc = sc + ?, gf = gf + ?, gc = gc + ?
            WHERE grupo_id = ? AND pareja_id = ?";
    
    $st1 = $conn->prepare($sql);
    $pp1 = !$win1 ? 1 : 0;
    $st1->bind_param("iiiiiiiii", $pts1, $win1, $pp1, $s1, $s2, $g1, $g2, $grupo_id, $p1_id);
    $st1->execute();

    $st2 = $conn->prepare($sql);
    $pp2 = !$win2 ? 1 : 0;
    $st2->bind_param("iiiiiiiii", $pts2, $win2, $pp2, $s2, $s1, $g2, $g1, $grupo_id, $p2_id);
    $st2->execute();
}

echo json_encode(["success" => true, "mensaje" => "Resultado guardado y progresión actualizada."]);
?>
