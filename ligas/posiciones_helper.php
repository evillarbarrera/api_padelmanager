<?php
/**
 * PADELBLOX - HELPER DE POSICIONES Y PUNTOS DE LIGAS
 * Recalcula y consolida de forma segura, no destructiva e infalible
 * los puntos, partidos jugados, sets y games de todas las parejas inscritas.
 */

mysqli_report(MYSQLI_REPORT_OFF);

/**
 * Obtiene y calcula en vivo las posiciones de una categoría o grupo específico
 * garantizando que todos los datos estén perfectamente calculados, sumados y ordenados.
 */
function obtenerPosicionesArray($conn, $categoria_id = null, $grupo_id = null) {
    $categoria_id = !empty($categoria_id) ? (int)$categoria_id : null;
    $grupo_id = !empty($grupo_id) ? (int)$grupo_id : null;

    // Si solo viene grupo_id, encontrar la categoría correspondiente
    if (!$categoria_id && $grupo_id > 0) {
        $resG = $conn->query("SELECT categoria_id FROM liga_grupos WHERE id = $grupo_id LIMIT 1");
        if ($resG && $rG = $resG->fetch_assoc()) {
            $categoria_id = (int)$rG['categoria_id'];
        }
    }

    if (!$categoria_id && !$grupo_id) {
        return [];
    }

    // 1. Obtener reglas de puntos de la liga
    $sql_reglas = "SELECT l.reglas_puntos_json 
                   FROM liga_categorias c 
                   JOIN ligas l ON c.liga_id = l.id 
                   WHERE c.id = $categoria_id LIMIT 1";
    $res_reglas = $conn->query($sql_reglas);
    $reglas = [];
    if ($res_reglas && $row_r = $res_reglas->fetch_assoc()) {
        $reglas = !empty($row_r['reglas_puntos_json']) ? json_decode($row_r['reglas_puntos_json'], true) : [];
    }

    $pts_vic_normal = isset($reglas['puntos_victoria_normal']) ? (int)$reglas['puntos_victoria_normal'] : 3;
    $pts_vic_decisivo = isset($reglas['puntos_victoria_decisivo']) ? (int)$reglas['puntos_victoria_decisivo'] : 2;
    $pts_der_jugada = isset($reglas['puntos_derrota_jugada']) ? (int)$reglas['puntos_derrota_jugada'] : 1;
    $pts_der_wo = isset($reglas['puntos_derrota_wo']) ? (int)$reglas['puntos_derrota_wo'] : 0;

    // 2. Verificar columna apellido en usuarios de forma segura
    $has_apellido = false;
    $check_ape = @$conn->query("SHOW COLUMNS FROM usuarios LIKE 'apellido'");
    if ($check_ape && $check_ape->num_rows > 0) {
        $has_apellido = true;
    }

    $colApe1 = $has_apellido ? "COALESCE(u1.apellido, '') as p1_apellido," : "'' as p1_apellido,";
    $colApe2 = $has_apellido ? "COALESCE(u2.apellido, '') as p2_apellido," : "'' as p2_apellido,";

    // Obtener todas las parejas activas de la categoría
    $wherePar = "par.categoria_id = $categoria_id AND (par.estado IS NULL OR par.estado = '' OR par.estado != 'Cancelada')";

    $sql_parejas = "SELECT 
                        par.id as pareja_id,
                        COALESCE(par.grupo_id, 0) as grupo_id,
                        par.nombre_pareja, par.nombre_externo1, par.nombre_externo2,
                        COALESCE(u1.nombre, '') as p1_nombre, $colApe1
                        COALESCE(u2.nombre, '') as p2_nombre, $colApe2
                        COALESCE(g.nombre, '') as grupo_nombre
                    FROM liga_parejas par
                    LEFT JOIN liga_grupos g ON par.grupo_id = g.id
                    LEFT JOIN usuarios u1 ON par.jugador1_id = u1.id
                    LEFT JOIN usuarios u2 ON par.jugador2_id = u2.id
                    WHERE $wherePar
                    ORDER BY par.id ASC";

    $res_parejas = @$conn->query($sql_parejas);
    if (!$res_parejas || $res_parejas->num_rows === 0) {
        return [];
    }

    $parejas = [];
    while ($p = $res_parejas->fetch_assoc()) {
        $pid = (int)$p['pareja_id'];
        $n1 = trim(($p['p1_nombre'] ?? '') . " " . ($p['p1_apellido'] ?? ''));
        if (empty($n1)) $n1 = !empty($p['nombre_externo1']) ? $p['nombre_externo1'] : 'Jugador 1';
        $n2 = trim(($p['p2_nombre'] ?? '') . " " . ($p['p2_apellido'] ?? ''));
        if (empty($n2)) $n2 = !empty($p['nombre_externo2']) ? $p['nombre_externo2'] : 'Jugador 2';

        $nomPareja = $p['nombre_pareja'];
        if (empty($nomPareja) || strpos($nomPareja, ' / ') !== false || $nomPareja === 'Pareja' || $nomPareja === 'Jugador 1 / Jugador 2') {
            $nomPareja = $n1 . " / " . $n2;
        }

        $parejas[$pid] = [
            'id' => $pid,
            'grupo_id' => (int)$p['grupo_id'],
            'pareja_id' => $pid,
            'nombre_pareja' => $nomPareja,
            'nombre_externo1' => $p['nombre_externo1'] ?? '',
            'nombre_externo2' => $p['nombre_externo2'] ?? '',
            'p1_nombre' => $p['p1_nombre'] ?? '',
            'p1_apellido' => $p['p1_apellido'] ?? '',
            'p1_foto' => $p['p1_foto'] ?? '',
            'p2_nombre' => $p['p2_nombre'] ?? '',
            'p2_apellido' => $p['p2_apellido'] ?? '',
            'p2_foto' => $p['p2_foto'] ?? '',
            'grupo_nombre' => $p['grupo_nombre'] ?? '',
            'pj' => 0,
            'pg' => 0,
            'pp' => 0,
            'puntos' => 0,
            'sets_favor' => 0,
            'sets_contra' => 0,
            'dif_sets' => 0,
            'games_favor' => 0,
            'games_contra' => 0,
            'dif_games' => 0,
            'wo_contra' => 0
        ];
    }

    $pIdsList = array_keys($parejas);
    if (empty($pIdsList)) {
        return [];
    }
    $pIdsStr = implode(',', $pIdsList);

    // 3. Obtener todos los partidos de la categoría jugados o con marcadores
    $sql_partidos = "SELECT * 
                     FROM liga_partidos 
                     WHERE (categoria_id = $categoria_id OR pareja1_id IN ($pIdsStr) OR pareja2_id IN ($pIdsStr))
                       AND (es_playoff IS NULL OR es_playoff = 0)
                       AND (
                           estado IN ('Jugado', 'Walkover')
                           OR (pareja_ganadora_id IS NOT NULL AND pareja_ganadora_id > 0)
                           OR (set1_p1 > 0 OR set1_p2 > 0 OR set2_p1 > 0 OR set2_p2 > 0)
                       )";
    $directMatches = [];
    $res_partidos = $conn->query($sql_partidos);

    if ($res_partidos) {
        while ($m = $res_partidos->fetch_assoc()) {
            $p1 = (int)$m['pareja1_id'];
            $p2 = (int)$m['pareja2_id'];

            // Sets individuales
            $s1 = 0; $s2 = 0;
            $set1_1 = (int)($m['set1_p1'] ?? 0); $set1_2 = (int)($m['set1_p2'] ?? 0);
            $set2_1 = (int)($m['set2_p1'] ?? 0); $set2_2 = (int)($m['set2_p2'] ?? 0);
            $set3_1 = (int)($m['set3_p1'] ?? 0); $set3_2 = (int)($m['set3_p2'] ?? 0);

            if ($set1_1 > $set1_2) $s1++; else if ($set1_2 > $set1_1) $s2++;
            if ($set2_1 > $set2_2) $s1++; else if ($set2_2 > $set2_1) $s2++;
            if ($set3_1 > $set3_2) $s1++; else if ($set3_2 > $set3_1) $s2++;

            $g1 = $set1_1 + $set2_1 + $set3_1;
            $g2 = $set1_2 + $set2_2 + $set3_2;

            // Ganador
            $win_id = !empty($m['pareja_ganadora_id']) ? (int)$m['pareja_ganadora_id'] : null;
            if (!$win_id) {
                if ($s1 > $s2) $win_id = $p1;
                else if ($s2 > $s1) $win_id = $p2;
            }

            if ($win_id && $p1 > 0 && $p2 > 0) {
                $directMatches[$p1 . '_' . $p2] = $win_id;
                $directMatches[$p2 . '_' . $p1] = $win_id;
            }

            $es_wo = (!empty($m['es_wo']) && ($m['es_wo'] == 1 || $m['es_wo'] === true || $m['es_wo'] === '1' || $m['estado'] === 'Walkover'));
            $es_decisivo = ($s1 + $s2 >= 3) || (!empty($m['es_super_tiebreak']) && $m['es_super_tiebreak'] == 1);

            // Puntos P1 y P2: Usar los guardados si existen, sino calcular según reglas
            $pts1 = isset($m['puntos_p1']) && $m['puntos_p1'] !== null && $m['puntos_p1'] !== '' 
                ? (int)$m['puntos_p1'] 
                : null;
            $pts2 = isset($m['puntos_p2']) && $m['puntos_p2'] !== null && $m['puntos_p2'] !== '' 
                ? (int)$m['puntos_p2'] 
                : null;

            if ($pts1 === null || ($pts1 === 0 && $win_id === $p1)) {
                if ($win_id === $p1) {
                    $pts1 = $es_decisivo ? $pts_vic_decisivo : $pts_vic_normal;
                } else {
                    $pts1 = $es_wo ? $pts_der_wo : $pts_der_jugada;
                }
            }

            if ($pts2 === null || ($pts2 === 0 && $win_id === $p2)) {
                if ($win_id === $p2) {
                    $pts2 = $es_decisivo ? $pts_vic_decisivo : $pts_vic_normal;
                } else {
                    $pts2 = $es_wo ? $pts_der_wo : $pts_der_jugada;
                }
            }

            // Sumar a Pareja 1 si pertenece a esta categoría
            if (isset($parejas[$p1])) {
                $parejas[$p1]['pj']++;
                if ($win_id === $p1) {
                    $parejas[$p1]['pg']++;
                } else if ($win_id !== null) {
                    $parejas[$p1]['pp']++;
                }
                $parejas[$p1]['puntos'] += $pts1;
                $parejas[$p1]['sets_favor'] += $s1;
                $parejas[$p1]['sets_contra'] += $s2;
                $parejas[$p1]['games_favor'] += $g1;
                $parejas[$p1]['games_contra'] += $g2;
                if ($es_wo && $win_id !== $p1) {
                    $parejas[$p1]['wo_contra']++;
                }
            }

            // Sumar a Pareja 2 si pertenece a esta categoría
            if (isset($parejas[$p2])) {
                $parejas[$p2]['pj']++;
                if ($win_id === $p2) {
                    $parejas[$p2]['pg']++;
                } else if ($win_id !== null) {
                    $parejas[$p2]['pp']++;
                }
                $parejas[$p2]['puntos'] += $pts2;
                $parejas[$p2]['sets_favor'] += $s2;
                $parejas[$p2]['sets_contra'] += $s1;
                $parejas[$p2]['games_favor'] += $g2;
                $parejas[$p2]['games_contra'] += $g1;
                if ($es_wo && $win_id !== $p2) {
                    $parejas[$p2]['wo_contra']++;
                }
            }
        }
    }

    // 4. Calcular diferencias y sincronizar con liga_posiciones de forma segura
    $resultadoFinal = [];
    foreach ($parejas as $pid => &$stats) {
        $stats['dif_sets'] = $stats['sets_favor'] - $stats['sets_contra'];
        $stats['dif_games'] = $stats['games_favor'] - $stats['games_contra'];

        // Sincronización pasiva a tabla liga_posiciones si existe
        try {
            $gId = (int)$stats['grupo_id'];
            $check = $conn->query("SELECT id FROM liga_posiciones WHERE pareja_id = $pid LIMIT 1");
            if ($check && $check->num_rows > 0) {
                $conn->query("UPDATE liga_posiciones SET 
                    pj = {$stats['pj']},
                    pg = {$stats['pg']},
                    pp = {$stats['pp']},
                    puntos = {$stats['puntos']},
                    sets_favor = {$stats['sets_favor']},
                    sets_contra = {$stats['sets_contra']},
                    dif_sets = {$stats['dif_sets']},
                    games_favor = {$stats['games_favor']},
                    games_contra = {$stats['games_contra']},
                    dif_games = {$stats['dif_games']},
                    wo_contra = {$stats['wo_contra']}
                    WHERE pareja_id = $pid");
            } else {
                $conn->query("INSERT INTO liga_posiciones (grupo_id, pareja_id, pj, pg, pp, puntos, sets_favor, sets_contra, dif_sets, games_favor, games_contra, dif_games, wo_contra) 
                    VALUES ($gId, $pid, {$stats['pj']}, {$stats['pg']}, {$stats['pp']}, {$stats['puntos']}, {$stats['sets_favor']}, {$stats['sets_contra']}, {$stats['dif_sets']}, {$stats['games_favor']}, {$stats['games_contra']}, {$stats['dif_games']}, {$stats['wo_contra']})");
            }
        } catch (Throwable $ignore) {}

        // Si se filtró por grupo, omitir si no coincide
        if ($grupo_id && $stats['grupo_id'] !== $grupo_id) {
            continue;
        }

        $resultadoFinal[] = $stats;
    }
    unset($stats);

    // 5. Ordenar por criterios oficiales: Puntos -> Enfrentamiento Directo (H2H) -> Dif Sets -> Dif Games -> Games Favor -> PG -> ID
    usort($resultadoFinal, function($a, $b) use ($directMatches) {
        if ($b['puntos'] !== $a['puntos']) return $b['puntos'] - $a['puntos'];

        // Criterio Oficial 1: Desempate por enfrentamiento directo (Head-to-Head)
        $h2hKey = $a['id'] . '_' . $b['id'];
        if (isset($directMatches[$h2hKey])) {
            $winner = $directMatches[$h2hKey];
            if ($winner === $a['id']) return -1;
            if ($winner === $b['id']) return 1;
        }

        // Criterios Secundarios
        if ($b['dif_sets'] !== $a['dif_sets']) return $b['dif_sets'] - $a['dif_sets'];
        if ($b['dif_games'] !== $a['dif_games']) return $b['dif_games'] - $a['dif_games'];
        if ($b['games_favor'] !== $a['games_favor']) return $b['games_favor'] - $a['games_favor'];
        if ($b['pg'] !== $a['pg']) return $b['pg'] - $a['pg'];
        return $a['id'] - $b['id'];
    });

    return $resultadoFinal;
}

/**
 * Función wrapper compatible para forzar recálculo
 */
function recalcularPosicionesCategoria($conn, $categoria_id) {
    obtenerPosicionesArray($conn, $categoria_id);
    return true;
}
?>
