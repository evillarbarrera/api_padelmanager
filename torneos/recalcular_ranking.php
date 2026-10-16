<?php
// api/torneos/recalcular_ranking.php
error_reporting(0);
ini_set('display_errors', '0');

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

mysqli_report(MYSQLI_REPORT_OFF);
require_once "../db.php";

try {
    // 1. Resetear puntos
    $conn->query("UPDATE usuarios SET puntos_ranking = 0");
    $conn->query("DELETE FROM ranking_categorias");

    // 2. Obtener torneos jugados en los últimos 2 meses
    $sqlT = "SELECT * FROM torneos_americanos WHERE fecha >= DATE_SUB(CURDATE(), INTERVAL 2 MONTH)";
    $resT = $conn->query($sqlT);

    $puntosPorUsuarioCat = []; // [usuario_id][cat] => puntos
    $puntosGlobales = [];      // [usuario_id] => puntos

    function sumarPts(&$pCat, &$pGlob, $uid, $cat, $pts) {
        if (!$uid || $uid <= 0 || $pts <= 0) return;
        if (!isset($pCat[$uid])) $pCat[$uid] = [];
        if (!isset($pCat[$uid][$cat])) $pCat[$uid][$cat] = 0;
        $pCat[$uid][$cat] += $pts;

        if (!isset($pGlob[$uid])) $pGlob[$uid] = 0;
        $pGlob[$uid] += $pts;
    }

    while ($t = $resT->fetch_assoc()) {
        $torneo_id = (int)$t['id'];
        $cat = $t['categoria'] ?: 'Cuarta';
        $p_part = (int)($t['puntos_participacion'] ?? 5);
        $p_oro_1 = (int)($t['puntos_1er_lugar'] ?? 100);
        $p_oro_2 = (int)($t['puntos_2do_lugar'] ?? 60);
        $p_plata_1 = (int)($t['puntos_3er_lugar'] ?? 40);
        $p_plata_2 = (int)($t['puntos_4to_lugar'] ?? 20);

        // A) Puntos de Participación
        $resP = $conn->query("SELECT jugador_id, jugador2_id FROM torneo_participantes WHERE torneo_id = $torneo_id");
        while ($p = $resP->fetch_assoc()) {
            sumarPts($puntosPorUsuarioCat, $puntosGlobales, (int)($p['jugador_id'] ?? 0), $cat, $p_part);
            sumarPts($puntosPorUsuarioCat, $puntosGlobales, (int)($p['jugador2_id'] ?? 0), $cat, $p_part);
        }

        // B) Puntos por Finales
        $resF = $conn->query("SELECT * FROM torneo_partidos WHERE torneo_id = $torneo_id AND fase = 'Finales' AND finalizado = 1");
        while ($f = $resF->fetch_assoc()) {
            $pt1 = (int)$f['puntos_t1'];
            $pt2 = (int)$f['puntos_t2'];
            $ganador = ($pt1 > $pt2) ? 1 : (($pt2 > $pt1) ? 2 : 0);

            $p1_jug1 = (int)($f['jugador1_id'] ?? 0);
            $p1_jug2 = (int)($f['jugador2_id'] ?? 0);
            $p2_jug1 = (int)($f['jugador3_id'] ?? 0);
            $p2_jug2 = (int)($f['jugador4_id'] ?? 0);

            if ($f['grupo_id'] === 'Oro') {
                if ($ganador === 1) {
                    sumarPts($puntosPorUsuarioCat, $puntosGlobales, $p1_jug1, $cat, $p_oro_1);
                    sumarPts($puntosPorUsuarioCat, $puntosGlobales, $p1_jug2, $cat, $p_oro_1);
                    sumarPts($puntosPorUsuarioCat, $puntosGlobales, $p2_jug1, $cat, $p_oro_2);
                    sumarPts($puntosPorUsuarioCat, $puntosGlobales, $p2_jug2, $cat, $p_oro_2);
                } elseif ($ganador === 2) {
                    sumarPts($puntosPorUsuarioCat, $puntosGlobales, $p2_jug1, $cat, $p_oro_1);
                    sumarPts($puntosPorUsuarioCat, $puntosGlobales, $p2_jug2, $cat, $p_oro_1);
                    sumarPts($puntosPorUsuarioCat, $puntosGlobales, $p1_jug1, $cat, $p_oro_2);
                    sumarPts($puntosPorUsuarioCat, $puntosGlobales, $p1_jug2, $cat, $p_oro_2);
                }
            } elseif ($f['grupo_id'] === 'Plata') {
                if ($ganador === 1) {
                    sumarPts($puntosPorUsuarioCat, $puntosGlobales, $p1_jug1, $cat, $p_plata_1);
                    sumarPts($puntosPorUsuarioCat, $puntosGlobales, $p1_jug2, $cat, $p_plata_1);
                    sumarPts($puntosPorUsuarioCat, $puntosGlobales, $p2_jug1, $cat, $p_plata_2);
                    sumarPts($puntosPorUsuarioCat, $puntosGlobales, $p2_jug2, $cat, $p_plata_2);
                } elseif ($ganador === 2) {
                    sumarPts($puntosPorUsuarioCat, $puntosGlobales, $p2_jug1, $cat, $p_plata_1);
                    sumarPts($puntosPorUsuarioCat, $puntosGlobales, $p2_jug2, $cat, $p_plata_1);
                    sumarPts($puntosPorUsuarioCat, $puntosGlobales, $p1_jug1, $cat, $p_plata_2);
                    sumarPts($puntosPorUsuarioCat, $puntosGlobales, $p1_jug2, $cat, $p_plata_2);
                }
            }
        }
    }

    // 3. Guardar en ranking_categorias
    $stmtCat = $conn->prepare("INSERT INTO ranking_categorias (usuario_id, categoria, puntos) VALUES (?, ?, ?)");
    foreach ($puntosPorUsuarioCat as $uid => $cats) {
        foreach ($cats as $catName => $pts) {
            if ($pts > 0) {
                $stmtCat->bind_param("isi", $uid, $catName, $pts);
                $stmtCat->execute();
            }
        }
    }

    // 4. Guardar en usuarios
    $stmtGlob = $conn->prepare("UPDATE usuarios SET puntos_ranking = ? WHERE id = ?");
    foreach ($puntosGlobales as $uid => $pts) {
        $stmtGlob->bind_param("ii", $pts, $uid);
        $stmtGlob->execute();
    }

    echo json_encode([
        "success" => true, 
        "mensaje" => "Ranking recalculado exitosamente con datos de los últimos 2 meses.",
        "jugadores_con_puntos" => count($puntosGlobales)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "error" => $e->getMessage()]);
}
?>
