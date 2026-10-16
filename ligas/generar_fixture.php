<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
require_once "../db.php";

$input = json_decode(file_get_contents("php://input"), true);
$categoria_id = $input['categoria_id'] ?? null;
$liga_id = $input['liga_id'] ?? null;
$num_grupos = (int)($input['num_grupos'] ?? 2);
$forzar_reinicio = !empty($input['forzar_reinicio']) ? (bool)$input['forzar_reinicio'] : false;

$categorias_a_generar = [];
if ($categoria_id) {
    $categorias_a_generar[] = (int)$categoria_id;
} else if ($liga_id) {
    $res_cats = $conn->query("SELECT id FROM liga_categorias WHERE liga_id = " . (int)$liga_id);
    if ($res_cats) {
        while ($rc = $res_cats->fetch_assoc()) {
            $categorias_a_generar[] = (int)$rc['id'];
        }
    }
}

if (empty($categorias_a_generar)) {
    echo json_encode(["success" => false, "error" => "ID de liga o categoría requerido"]);
    exit;
}

try {
    $conn->begin_transaction();

    foreach ($categorias_a_generar as $cat_id) {
        // Verificar si la categoría tiene partidos jugados con resultados
        $chkPlayed = $conn->query("SELECT COUNT(*) as cant FROM liga_partidos 
                                   WHERE (categoria_id = $cat_id OR pareja1_id IN (SELECT id FROM liga_parejas WHERE categoria_id = $cat_id)) 
                                     AND (estado IN ('Jugado', 'Walkover') 
                                          OR (pareja_ganadora_id IS NOT NULL AND pareja_ganadora_id > 0) 
                                          OR (set1_p1 IS NOT NULL AND set1_p1 > 0) 
                                          OR (set1_p2 IS NOT NULL AND set1_p2 > 0))");
        $cantPlayed = ($chkPlayed && $rP = $chkPlayed->fetch_assoc()) ? (int)$rP['cant'] : 0;

        if ($cantPlayed > 0 && !$forzar_reinicio) {
            throw new Exception("La categoría ya cuenta con $cantPlayed partido(s) jugados y marcadores registrados. Para no perder los resultados utiliza la opción 'Completar Faltantes' o 'Programar Jornada'. Si realmente deseas reiniciar todo el fixture y borrar los marcadores desde cero, debes confirmar el reinicio total.");
        }

        // 0. Limpiar reservas de canchas, partidos, jornadas, posiciones, grupos previas de esta categoría
        $conn->query("DELETE FROM reservas_cancha WHERE liga_partido_id IN (SELECT id FROM liga_partidos WHERE categoria_id = $cat_id)");
        $conn->query("DELETE FROM liga_partidos WHERE (categoria_id = $cat_id OR pareja1_id IN (SELECT id FROM liga_parejas WHERE categoria_id = $cat_id)) AND es_playoff = 0");
        $conn->query("DELETE FROM liga_jornadas WHERE categoria_id = $cat_id");
        $conn->query("DELETE FROM liga_posiciones WHERE grupo_id IN (SELECT id FROM liga_grupos WHERE categoria_id = $cat_id)");
        $conn->query("DELETE FROM liga_grupos WHERE categoria_id = $cat_id");
        $conn->query("UPDATE liga_parejas SET grupo_id = NULL WHERE categoria_id = $cat_id");

        // Asegurar que todas las parejas activas estén en estado Confirmada
        $conn->query("UPDATE liga_parejas SET estado = 'Confirmada' WHERE categoria_id = $cat_id AND (estado IS NULL OR estado = '' OR estado != 'Cancelada')");

        // 1. Obtener todas las parejas reales inscritas en esta categoría
        $res = $conn->query("SELECT id FROM liga_parejas 
                             WHERE categoria_id = $cat_id 
                               AND (estado IS NULL OR estado = '' OR estado != 'Cancelada') 
                               AND (
                                   (jugador1_id IS NOT NULL AND jugador1_id > 0) 
                                   OR (jugador2_id IS NOT NULL AND jugador2_id > 0)
                                   OR (nombre_externo1 IS NOT NULL AND TRIM(nombre_externo1) != '')
                                   OR (nombre_externo2 IS NOT NULL AND TRIM(nombre_externo2) != '')
                                   OR (nombre_pareja IS NOT NULL AND TRIM(nombre_pareja) != '')
                               ) 
                             ORDER BY id ASC");
        $parejas = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $parejas[] = $row['id'];
            }
        }

        $total_parejas = count($parejas);

        if ($total_parejas < 2) {
            throw new Exception("La categoría requiere al menos 2 parejas reales inscritas para generar el fixture.");
        }

        // Mezclar las parejas aleatoriamente para una distribución equitativa
        shuffle($parejas);

        // 3. Crear Grupos
        $grupos_ids = [];
        for ($g = 0; $g < $num_grupos; $g++) {
            $letra = chr(65 + $g);
            $stmt = $conn->prepare("INSERT INTO liga_grupos (categoria_id, nombre) VALUES (?, ?)");
            $nombre_grupo = "Grupo $letra";
            $stmt->bind_param("is", $cat_id, $nombre_grupo);
            $stmt->execute();
            $grupos_ids[] = $stmt->insert_id;
        }

        // 4. Asignación de parejas por método víbora (Snake distribution)
        $grupos_parejas = array_fill(0, $num_grupos, []);
        foreach ($parejas as $idx => $pareja_id) {
            $g_idx = $idx % $num_grupos;
            $grupos_parejas[$g_idx][] = $pareja_id;
            
            $grupo_id = $grupos_ids[$g_idx];
            $conn->query("UPDATE liga_parejas SET grupo_id = $grupo_id WHERE id = $pareja_id");
            $conn->query("INSERT INTO liga_posiciones (grupo_id, pareja_id) VALUES ($grupo_id, $pareja_id) ON DUPLICATE KEY UPDATE id=id");
        }

        // 5. Algoritmo de Berger para partidos Round Robin por grupo
        foreach ($grupos_ids as $g_idx => $grupo_id) {
            $team_list = $grupos_parejas[$g_idx];
            if (count($team_list) % 2 != 0) {
                $team_list[] = null;
            }

            $n = count($team_list);
            $num_jornadas = $n - 1;
            $matches_per_round = $n / 2;

            for ($j = 0; $j < $num_jornadas; $j++) {
                $num_jornada = $j + 1;
                $res_j = $conn->query("SELECT id FROM liga_jornadas WHERE categoria_id = $cat_id AND numero_jornada = $num_jornada");
                if ($res_j && $res_j->num_rows > 0) {
                    $jornada_id = $res_j->fetch_assoc()['id'];
                } else {
                    $stmt_j = $conn->prepare("INSERT INTO liga_jornadas (categoria_id, numero_jornada, nombre) VALUES (?, ?, ?)");
                    $nombre_j = "Jornada $num_jornada";
                    $stmt_j->bind_param("iis", $cat_id, $num_jornada, $nombre_j);
                    $stmt_j->execute();
                    $jornada_id = $stmt_j->insert_id;
                }

                for ($m = 0; $m < $matches_per_round; $m++) {
                    $p1 = $team_list[$m];
                    $p2 = $team_list[$n - 1 - $m];

                    if ($p1 !== null && $p2 !== null) {
                        $stmt_match = $conn->prepare("INSERT INTO liga_partidos (categoria_id, jornada_id, grupo_id, pareja1_id, pareja2_id, estado) VALUES (?, ?, ?, ?, ?, 'Programado')");
                        $stmt_match->bind_param("iiiii", $cat_id, $jornada_id, $grupo_id, $p1, $p2);
                        $stmt_match->execute();
                    }
                }

                $last = array_pop($team_list);
                array_splice($team_list, 1, 0, [$last]);
            }
        }
    }

    $conn->commit();
    echo json_encode(["success" => true, "mensaje" => "Fixture de liga generado exitosamente para " . count($categorias_a_generar) . " categorías"]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(200);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
