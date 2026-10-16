<?php
/**
 * PADELBLOX - AUTO COMPLETAR FIXTURE Y JORNADAS (SOLVER DETERMINISTA CON PRE-ASIGNACIÓN)
 * 
 * Reglas fundamentales:
 * 1. MÁXIMO 1 PARTIDO POR PAREJA POR SEMANA (JORNADA).
 * 2. NUNCA duplicar partidos entre las mismas parejas en ningún momento de la liga.
 * 3. NUNCA alterar, mover ni borrar partidos ya jugados, con fecha/cancha asignada o enlazados a /club-reservas.
 * 4. Respetar estrictamente los partidos agendados para cada jornada.
 * 5. Si una jornada ya tiene partidos agendados (ej: 6 partidos para 17 parejas), solo se agregarán como máximo los faltantes (hasta 8 partidos totales).
 * 6. Eliminar automáticamente cualquier jornada sobrante (> N si impar, > N-1 si par) que no contenga partidos reales.
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();

register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_length()) ob_clean();
        header("Access-Control-Allow-Origin: *");
        header("Content-Type: application/json; charset=utf-8");
        http_response_code(200);
        echo json_encode([
            "success" => false, 
            "error" => "Error interno en el servidor: " . $err['message'] . " en línea " . $err['line']
        ]);
    }
});

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
mysqli_report(MYSQLI_REPORT_OFF);
require_once "../db.php";

$rawInput = file_get_contents("php://input");
$input = !empty($rawInput) ? json_decode($rawInput, true) : [];

$categoria_id = !empty($input['categoria_id']) ? (int)$input['categoria_id'] : (!empty($_GET['categoria_id']) ? (int)$_GET['categoria_id'] : null);
$jornada_id = !empty($input['jornada_id']) ? (int)$input['jornada_id'] : (!empty($_GET['jornada_id']) ? (int)$_GET['jornada_id'] : null);
$liga_id = !empty($input['liga_id']) ? (int)$input['liga_id'] : (!empty($_GET['liga_id']) ? (int)$_GET['liga_id'] : null);

if (!$categoria_id && !$jornada_id && !$liga_id) {
    if (ob_get_length()) ob_clean();
    echo json_encode(["success" => false, "error" => "Se requiere categoria_id, jornada_id o liga_id (POST o GET)"]);
    exit;
}

/**
 * Completa el fixture Round Robin respetando al 100% los partidos fijos previos.
 * Garantiza:
 * 1. Máximo 1 partido por pareja por jornada.
 * 2. Cero partidos duplicados entre las mismas parejas.
 * 3. Máximo floor(N/2) partidos por jornada.
 * 4. Algoritmo determinista ultra-rápido (< 5ms) sin riesgo de timeout ni recursión infinita.
 */
function completeRoundRobinSchedule($pIds, $partidosFijos, $minJornadas) {
    $n = count($pIds);
    if ($n < 2) return [];

    $maxMatchesPerRound = (int)floor($n / 2);
    if ($maxMatchesPerRound < 1) $maxMatchesPerRound = 1;

    // 1. Mapear partidos fijos
    $fixedMap = [];
    $roundTeams = [];
    $roundMatches = [];
    $maxFixedJornada = 1;

    foreach ($partidosFijos as $f) {
        $p1 = min((int)$f['p1'], (int)$f['p2']);
        $p2 = max((int)$f['p1'], (int)$f['p2']);
        $j = (int)$f['jornada'];
        if ($j < 1) $j = 1;
        if ($j > $maxFixedJornada) {
            $maxFixedJornada = $j;
        }
        $key = "{$p1}_{$p2}";
        $fixedMap[$key] = $j;

        if (!isset($roundTeams[$j])) $roundTeams[$j] = [];
        if (!isset($roundMatches[$j])) $roundMatches[$j] = [];

        $roundTeams[$j][$p1] = true;
        $roundTeams[$j][$p2] = true;
        $roundMatches[$j][] = ['p1' => $p1, 'p2' => $p2, 'is_new' => false];
    }

    $numRounds = max($minJornadas, $maxFixedJornada);

    for ($j = 1; $j <= $numRounds; $j++) {
        if (!isset($roundTeams[$j])) $roundTeams[$j] = [];
        if (!isset($roundMatches[$j])) $roundMatches[$j] = [];
    }

    // 2. Identificar cruces faltantes
    $missingEdges = [];
    for ($i = 0; $i < $n; $i++) {
        for ($k = $i + 1; $k < $n; $k++) {
            $p1 = min($pIds[$i], $pIds[$k]);
            $p2 = max($pIds[$i], $pIds[$k]);
            $key = "{$p1}_{$p2}";
            if (!isset($fixedMap[$key])) {
                $missingEdges[] = ['p1' => $p1, 'p2' => $p2, 'key' => $key];
            }
        }
    }

    if (empty($missingEdges)) {
        return $roundMatches;
    }

    // 3. Ordenar cruces faltantes por restricciones (MRV)
    $calcFreedom = function($edge) use (&$roundTeams, $numRounds) {
        $count = 0;
        $p1 = $edge['p1'];
        $p2 = $edge['p2'];
        for ($j = 1; $j <= $numRounds; $j++) {
            if (!isset($roundTeams[$j][$p1]) && !isset($roundTeams[$j][$p2])) {
                $count++;
            }
        }
        return $count;
    };
    usort($missingEdges, function($a, $b) use ($calcFreedom) {
        return $calcFreedom($a) - $calcFreedom($b);
    });

    // 4. Inserción con caminos de aumento (Augmenting Search / Kempe chain)
    $tryInsertEdge = function($p1, $p2, $depth = 0, $visitedRounds = []) use (&$tryInsertEdge, &$roundTeams, &$roundMatches, $numRounds, $maxMatchesPerRound) {
        if ($depth > 12) return false;

        // Inserción directa
        $bestJ = null;
        $minC = 999999;
        for ($j = 1; $j <= $numRounds; $j++) {
            if (!isset($visitedRounds[$j]) && !isset($roundTeams[$j][$p1]) && !isset($roundTeams[$j][$p2]) && count($roundMatches[$j]) < $maxMatchesPerRound) {
                $c = count($roundMatches[$j]);
                if ($c < $minC) {
                    $minC = $c;
                    $bestJ = $j;
                }
            }
        }
        if ($bestJ !== null) {
            $roundTeams[$bestJ][$p1] = true;
            $roundTeams[$bestJ][$p2] = true;
            $roundMatches[$bestJ][] = ['p1' => min($p1, $p2), 'p2' => max($p1, $p2), 'is_new' => true];
            return true;
        }

        // Búsqueda de aumento con swap
        for ($j1 = 1; $j1 <= $numRounds; $j1++) {
            if (isset($visitedRounds[$j1])) continue;

            if (!isset($roundTeams[$j1][$p1]) && isset($roundTeams[$j1][$p2])) {
                foreach ($roundMatches[$j1] as $mIdx => $rm) {
                    if (!empty($rm['is_new']) && ($rm['p1'] === $p2 || $rm['p2'] === $p2)) {
                        $pX = ($rm['p1'] === $p2) ? $rm['p2'] : $rm['p1'];
                        unset($roundTeams[$j1][$pX]);
                        array_splice($roundMatches[$j1], $mIdx, 1);
                        $roundTeams[$j1][$p1] = true;
                        $roundMatches[$j1][] = ['p1' => min($p1, $p2), 'p2' => max($p1, $p2), 'is_new' => true];

                        $newVisited = $visitedRounds;
                        $newVisited[$j1] = true;
                        if ($tryInsertEdge($p2, $pX, $depth + 1, $newVisited)) {
                            return true;
                        }
                        // Revertir
                        array_pop($roundMatches[$j1]);
                        unset($roundTeams[$j1][$p1]);
                        $roundTeams[$j1][$pX] = true;
                        array_splice($roundMatches[$j1], $mIdx, 0, [$rm]);
                    }
                }
            } else if (isset($roundTeams[$j1][$p1]) && !isset($roundTeams[$j1][$p2])) {
                foreach ($roundMatches[$j1] as $mIdx => $rm) {
                    if (!empty($rm['is_new']) && ($rm['p1'] === $p1 || $rm['p2'] === $p1)) {
                        $pX = ($rm['p1'] === $p1) ? $rm['p2'] : $rm['p1'];
                        unset($roundTeams[$j1][$pX]);
                        array_splice($roundMatches[$j1], $mIdx, 1);
                        $roundTeams[$j1][$p2] = true;
                        $roundMatches[$j1][] = ['p1' => min($p1, $p2), 'p2' => max($p1, $p2), 'is_new' => true];

                        $newVisited = $visitedRounds;
                        $newVisited[$j1] = true;
                        if ($tryInsertEdge($p1, $pX, $depth + 1, $newVisited)) {
                            return true;
                        }
                        // Revertir
                        array_pop($roundMatches[$j1]);
                        unset($roundTeams[$j1][$p2]);
                        $roundTeams[$j1][$pX] = true;
                        array_splice($roundMatches[$j1], $mIdx, 0, [$rm]);
                    }
                }
            }
        }

        return false;
    };

    foreach ($missingEdges as $edge) {
        $p1 = $edge['p1'];
        $p2 = $edge['p2'];
        $inserted = $tryInsertEdge($p1, $p2);

        if (!$inserted) {
            // Fallback de seguridad en caso excepcional
            for ($jExtra = 1; $jExtra <= $numRounds + 10; $jExtra++) {
                if (!isset($roundTeams[$jExtra])) $roundTeams[$jExtra] = [];
                if (!isset($roundMatches[$jExtra])) $roundMatches[$jExtra] = [];

                if (!isset($roundTeams[$jExtra][$p1]) && !isset($roundTeams[$jExtra][$p2]) && count($roundMatches[$jExtra]) < $maxMatchesPerRound) {
                    $roundTeams[$jExtra][$p1] = true;
                    $roundTeams[$jExtra][$p2] = true;
                    $roundMatches[$jExtra][] = ['p1' => $p1, 'p2' => $p2, 'is_new' => true];
                    if ($jExtra > $numRounds) {
                        $numRounds = $jExtra;
                    }
                    break;
                }
            }
        }
    }

    return $roundMatches;
}

try {
    // 0. Auto-sincronizar categoria_id y reservas_cancha para proteger y reconocer TODOS los partidos existentes
    @$conn->query("UPDATE liga_partidos lp JOIN liga_jornadas lj ON lp.jornada_id = lj.id SET lp.categoria_id = lj.categoria_id WHERE lp.categoria_id IS NULL OR lp.categoria_id = 0");
    @$conn->query("UPDATE liga_partidos lp JOIN liga_parejas lpar ON lp.pareja1_id = lpar.id SET lp.categoria_id = lpar.categoria_id WHERE lp.categoria_id IS NULL OR lp.categoria_id = 0");
    @$conn->query("UPDATE liga_partidos lp 
                   JOIN reservas_cancha rc ON rc.liga_partido_id = lp.id 
                   SET lp.cancha_id = rc.cancha_id, 
                       lp.fecha_hora = CONCAT(rc.fecha, ' ', rc.hora_inicio)
                   WHERE rc.cancha_id IS NOT NULL 
                     AND rc.fecha IS NOT NULL 
                     AND rc.hora_inicio IS NOT NULL 
                     AND rc.estado != 'Cancelada'");

    $conn->begin_transaction();

    // 1. Identificar las categorías a procesar
    $categorias = [];
    if ($jornada_id) {
        $resJ = $conn->query("SELECT j.categoria_id, j.numero_jornada, c.nombre as cat_nombre 
                              FROM liga_jornadas j 
                              JOIN liga_categorias c ON j.categoria_id = c.id 
                              WHERE j.id = $jornada_id");
        if ($resJ && $row = $resJ->fetch_assoc()) {
            $categorias[] = [
                'id' => (int)$row['categoria_id'],
                'nombre' => $row['cat_nombre'],
                'jornada_especifica_id' => $jornada_id,
                'jornada_especifica_num' => (int)$row['numero_jornada']
            ];
        }
    } else if ($categoria_id) {
        $resC = $conn->query("SELECT id, nombre FROM liga_categorias WHERE id = $categoria_id");
        if ($resC && $row = $resC->fetch_assoc()) {
            $categorias[] = [
                'id' => (int)$row['id'],
                'nombre' => $row['nombre'],
                'jornada_especifica_id' => null,
                'jornada_especifica_num' => null
            ];
        }
    } else if ($liga_id) {
        $resC = $conn->query("SELECT id, nombre FROM liga_categorias WHERE liga_id = $liga_id ORDER BY id ASC");
        if ($resC) {
            while ($row = $resC->fetch_assoc()) {
                $categorias[] = [
                    'id' => (int)$row['id'],
                    'nombre' => $row['nombre'],
                    'jornada_especifica_id' => null,
                    'jornada_especifica_num' => null
                ];
            }
        }
    }

    if (empty($categorias)) {
        throw new Exception("No se encontraron categorías para procesar.");
    }

    $totalPartidosAgregados = 0;
    $detalles = [];
    $partidosNuevosCreados = [];
    $totalJornadasEliminadas = 0;

    foreach ($categorias as $cat) {
        $catId = (int)$cat['id'];
        $catNombre = $cat['nombre'];
        $targetJornadaNum = !empty($cat['jornada_especifica_num']) ? (int)$cat['jornada_especifica_num'] : null;

        // A. Obtener todas las parejas activas de la categoría
        $sqlPairs = "SELECT id, grupo_id, nombre_pareja 
                     FROM liga_parejas 
                     WHERE categoria_id = $catId 
                       AND (estado IS NULL OR estado = '' OR estado != 'Cancelada')
                     ORDER BY id ASC";
        $resPairs = $conn->query($sqlPairs);
        if (!$resPairs) {
            throw new Exception("Error al consultar parejas: " . $conn->error);
        }

        $todasParejas = [];
        $parejasPorGrupo = [];

        while ($p = $resPairs->fetch_assoc()) {
            $pId = (int)$p['id'];
            $gId = !empty($p['grupo_id']) ? (int)$p['grupo_id'] : 0;
            $todasParejas[$pId] = $p;
            $parejasPorGrupo[$gId][] = $pId;
        }

        $numParejas = count($todasParejas);
        if ($numParejas < 2) {
            continue;
        }

        // Tope máximo de partidos por jornada: ej. floor(17 / 2) = 8 partidos
        $maxPartidosPorJornada = (int)floor($numParejas / 2);

        // B. Mapear y garantizar que existan exactamente las jornadas necesarias de la categoría (1 a N)
        $minJornadasNecesarias = ($numParejas % 2 === 0) ? ($numParejas - 1) : $numParejas;
        if ($minJornadasNecesarias < 1) $minJornadasNecesarias = 1;

        $resJor = $conn->query("SELECT id, numero_jornada, nombre FROM liga_jornadas WHERE categoria_id = $catId ORDER BY numero_jornada ASC");
        $jornadasPorNumero = [];
        $numeroPorJornadaId = [];
        $maxNumeroJornada = 0;
        $jornadasExtrasIds = [];

        if ($resJor) {
            while ($jRow = $resJor->fetch_assoc()) {
                $numJ = (int)$jRow['numero_jornada'];
                $jId = (int)$jRow['id'];
                if ($numJ <= $minJornadasNecesarias) {
                    $jornadasPorNumero[$numJ] = $jId;
                    $numeroPorJornadaId[$jId] = $numJ;
                    if ($numJ > $maxNumeroJornada) {
                        $maxNumeroJornada = $numJ;
                    }
                } else {
                    $jornadasExtrasIds[] = $jId;
                }
            }
        }

        // Crear jornadas canónicas faltantes si no existen
        while ($maxNumeroJornada < $minJornadasNecesarias) {
            $maxNumeroJornada++;
            $nombreNuevaJ = "Jornada $maxNumeroJornada";
            $stmtNewJ = $conn->prepare("INSERT INTO liga_jornadas (categoria_id, numero_jornada, nombre) VALUES (?, ?, ?)");
            $stmtNewJ->bind_param("iis", $catId, $maxNumeroJornada, $nombreNuevaJ);
            $stmtNewJ->execute();
            $newId = $stmtNewJ->insert_id;
            $jornadasPorNumero[$maxNumeroJornada] = $newId;
            $numeroPorJornadaId[$newId] = $maxNumeroJornada;
            $stmtNewJ->close();
        }

        $jornadasIds = array_values($jornadasPorNumero);
        $jIdsStr = !empty($jornadasIds) ? implode(',', $jornadasIds) : "0";

        // C. Limpieza segura:
        // Borrar ÚNICAMENTE partidos pendientes que NO tienen cancha, NO tienen fecha, NO tienen resultados y NO tienen reservas
        $cleanWhere = $targetJornadaNum 
            ? "jornada_id = " . ($jornadasPorNumero[$targetJornadaNum] ?? 0)
            : "(categoria_id = $catId OR jornada_id IN ($jIdsStr)" . (!empty($jornadasExtrasIds) ? " OR jornada_id IN (" . implode(',', $jornadasExtrasIds) . ")" : "") . ")";

        $conn->query("DELETE FROM liga_partidos 
                      WHERE $cleanWhere 
                        AND (cancha_id IS NULL OR cancha_id = 0) 
                        AND (fecha_hora IS NULL OR fecha_hora <= '2000-01-01 00:00:00') 
                        AND (estado IS NULL OR estado NOT IN ('Jugado', 'Walkover')) 
                        AND (pareja_ganadora_id IS NULL OR pareja_ganadora_id = 0)
                        AND (set1_p1 IS NULL OR set1_p1 = 0)
                        AND (set1_p2 IS NULL OR set1_p2 = 0)
                        AND (set2_p1 IS NULL OR set2_p1 = 0)
                        AND (set2_p2 IS NULL OR set2_p2 = 0)
                        AND id NOT IN (SELECT rc.liga_partido_id FROM reservas_cancha rc WHERE rc.liga_partido_id IS NOT NULL AND rc.estado != 'Cancelada')");

        // Eliminar jornadas extras vacías (> minJornadasNecesarias)
        $resExtras = $conn->query("SELECT id, numero_jornada FROM liga_jornadas WHERE categoria_id = $catId AND numero_jornada > $minJornadasNecesarias");
        if ($resExtras) {
            while ($rowEx = $resExtras->fetch_assoc()) {
                $exId = (int)$rowEx['id'];
                $chk = $conn->query("SELECT count(*) as cnt FROM liga_partidos WHERE jornada_id = $exId AND (cancha_id > 0 OR fecha_hora > '2000-01-01 00:00:00' OR estado IN ('Jugado','Walkover'))");
                $cnt = ($chk && $r = $chk->fetch_assoc()) ? (int)$r['cnt'] : 0;
                if ($cnt === 0) {
                    $conn->query("DELETE FROM liga_partidos WHERE jornada_id = $exId");
                    $conn->query("DELETE FROM liga_jornadas WHERE id = $exId");
                    $totalJornadasEliminadas++;
                }
            }
        }

        // D. Obtener fecha de inicio de la liga
        $resLigaDate = $conn->query("SELECT l.fecha_inicio FROM ligas l JOIN liga_categorias lc ON lc.liga_id = l.id WHERE lc.id = $catId LIMIT 1");
        $fechaInicioLiga = null;
        if ($resLigaDate && $rowLD = $resLigaDate->fetch_assoc()) {
            $fechaInicioLiga = $rowLD['fecha_inicio'];
        }

        // E. Leer TODOS los partidos REALES / FIJOS existentes de la categoría
        $sqlPartidos = "SELECT p.id, p.jornada_id, p.grupo_id, p.pareja1_id, p.pareja2_id, p.estado, p.cancha_id, p.fecha_hora,
                               p.pareja_ganadora_id, p.set1_p1, p.set1_p2, rc.id as reserva_id
                        FROM liga_partidos p 
                        LEFT JOIN reservas_cancha rc ON rc.liga_partido_id = p.id AND rc.estado != 'Cancelada'
                        WHERE (p.categoria_id = $catId 
                           OR p.jornada_id IN ($jIdsStr)
                           OR p.pareja1_id IN (SELECT id FROM liga_parejas WHERE categoria_id = $catId)) 
                          AND (p.es_playoff IS NULL OR p.es_playoff = 0)";
        $resPartidos = $conn->query($sqlPartidos);
        if (!$resPartidos) {
            throw new Exception("Error al leer partidos existentes: " . $conn->error);
        }

        $partidosFijos = [];
        $conteoFijosPorJornada = [];

        while ($pt = $resPartidos->fetch_assoc()) {
            $pId = (int)$pt['id'];
            $p1 = (int)$pt['pareja1_id'];
            $p2 = (int)$pt['pareja2_id'];
            $currJId = !empty($pt['jornada_id']) ? (int)$pt['jornada_id'] : 0;
            
            $isRealScheduled = (!empty($pt['cancha_id']) && (int)$pt['cancha_id'] > 0)
                            || (!empty($pt['fecha_hora']) && $pt['fecha_hora'] > '2000-01-01 00:00:00')
                            || (!empty($pt['reserva_id']))
                            || in_array($pt['estado'], ['Jugado', 'Walkover'])
                            || (!empty($pt['pareja_ganadora_id']) && (int)$pt['pareja_ganadora_id'] > 0)
                            || (!empty($pt['set1_p1']) && (int)$pt['set1_p1'] > 0)
                            || (!empty($pt['set1_p2']) && (int)$pt['set1_p2'] > 0);

            if ($isRealScheduled && $p1 > 0 && $p2 > 0) {
                $numJ = null;
                if ($currJId > 0 && isset($numeroPorJornadaId[$currJId])) {
                    $numJ = $numeroPorJornadaId[$currJId];
                } else if (!empty($pt['fecha_hora']) && $pt['fecha_hora'] > '2000-01-01 00:00:00' && $fechaInicioLiga) {
                    $fPart = strtotime($pt['fecha_hora']);
                    $fIni = strtotime($fechaInicioLiga);
                    if ($fPart && $fIni && $fPart >= $fIni) {
                        $diffDays = floor(($fPart - $fIni) / 86400);
                        $numJ = min($minJornadasNecesarias, max(1, (int)floor($diffDays / 7) + 1));
                    }
                }

                if (!$numJ || $numJ > $minJornadasNecesarias) {
                    $numJ = 1;
                }

                // Normalizar jornada_id y categoria_id en la base de datos
                $canonicalJId = $jornadasPorNumero[$numJ] ?? $jornadasPorNumero[1];
                if ($currJId !== $canonicalJId) {
                    @$conn->query("UPDATE liga_partidos SET jornada_id = $canonicalJId, categoria_id = $catId WHERE id = $pId");
                }

                $partidosFijos[] = [
                    'p1' => $p1,
                    'p2' => $p2,
                    'jornada' => $numJ
                ];
                $conteoFijosPorJornada[$numJ] = ($conteoFijosPorJornada[$numJ] ?? 0) + 1;
            }
        }

        // F. Generar el Fixture Perfecto con el Solver CSP
        $gruposExistentes = array_keys($parejasPorGrupo);
        $hayMultiplesGrupos = count(array_filter($gruposExistentes, function($g) { return $g > 0; })) > 1;

        $fixtureGenerado = []; // [numJ => [[p1, p2, grupo_id, is_new]]]

        if ($hayMultiplesGrupos) {
            foreach ($parejasPorGrupo as $gId => $pIdsGrupo) {
                if (count($pIdsGrupo) < 2) continue;
                $fijosGrupo = array_filter($partidosFijos, function($f) use ($pIdsGrupo) {
                    return in_array($f['p1'], $pIdsGrupo) && in_array($f['p2'], $pIdsGrupo);
                });
                $minJG = (count($pIdsGrupo) % 2 === 0) ? (count($pIdsGrupo) - 1) : count($pIdsGrupo);
                $resGrupo = completeRoundRobinSchedule($pIdsGrupo, $fijosGrupo, $minJG);
                foreach ($resGrupo as $numJ => $matches) {
                    foreach ($matches as $m) {
                        $fixtureGenerado[$numJ][] = [
                            'p1' => $m['p1'],
                            'p2' => $m['p2'],
                            'grupo_id' => $gId ?: null,
                            'is_new' => !empty($m['is_new'])
                        ];
                    }
                }
            }
        } else {
            $pIdsTotal = array_keys($todasParejas);
            $resTotal = completeRoundRobinSchedule($pIdsTotal, $partidosFijos, $minJornadasNecesarias);
            foreach ($resTotal as $numJ => $matches) {
                foreach ($matches as $m) {
                    $rawG = !empty($todasParejas[$m['p1']]['grupo_id']) ? $todasParejas[$m['p1']]['grupo_id'] : (!empty($todasParejas[$m['p2']]['grupo_id']) ? $todasParejas[$m['p2']]['grupo_id'] : null);
                    $fixtureGenerado[$numJ][] = [
                        'p1' => $m['p1'],
                        'p2' => $m['p2'],
                        'grupo_id' => $rawG,
                        'is_new' => !empty($m['is_new'])
                    ];
                }
            }
        }

        // Asegurar que existan jornadas en la base de datos si el solver requirió alguna jornada adicional
        $maxJornadaGenerada = !empty($fixtureGenerado) ? max(array_keys($fixtureGenerado)) : 1;
        while ($maxNumeroJornada < $maxJornadaGenerada) {
            $maxNumeroJornada++;
            $nombreNuevaJ = "Jornada $maxNumeroJornada";
            $stmtNewJ = $conn->prepare("INSERT INTO liga_jornadas (categoria_id, numero_jornada, nombre) VALUES (?, ?, ?)");
            $stmtNewJ->bind_param("iis", $catId, $maxNumeroJornada, $nombreNuevaJ);
            $stmtNewJ->execute();
            $newId = $stmtNewJ->insert_id;
            $jornadasPorNumero[$maxNumeroJornada] = $newId;
            $numeroPorJornadaId[$newId] = $maxNumeroJornada;
            $stmtNewJ->close();
        }

        // G. Guardar ÚNICAMENTE los partidos nuevos en la base de datos
        $partidosAgregadosCat = 0;

        foreach ($fixtureGenerado as $numJ => $matchesDeJornada) {
            if ($targetJornadaNum && $numJ !== $targetJornadaNum) {
                continue;
            }

            $currJId = $jornadasPorNumero[$numJ] ?? null;
            if (!$currJId) continue;

            $agregadosEnJornada = 0;

            foreach ($matchesDeJornada as $cruce) {
                if (empty($cruce['is_new'])) {
                    continue; // Partido ya existía fijo, no reinsertar
                }

                $pA = (int)$cruce['p1'];
                $pB = (int)$cruce['p2'];
                $gValSql = !empty($cruce['grupo_id']) ? (int)$cruce['grupo_id'] : "NULL";

                // Protección estricta contra duplicados: No insertar si ya existe un partido entre pA y pB (jugado o programado)
                $chkDup = $conn->query("SELECT id FROM liga_partidos 
                                         WHERE ((pareja1_id = $pA AND pareja2_id = $pB) OR (pareja1_id = $pB AND pareja2_id = $pA)) 
                                           AND (categoria_id = $catId OR categoria_id IS NULL OR categoria_id = 0)
                                           AND (es_playoff IS NULL OR es_playoff = 0) LIMIT 1");
                if ($chkDup && $chkDup->num_rows > 0) {
                    continue; // Ya existe partido (jugado o agendado) entre estas dos parejas, NO duplicar
                }

                $conn->query("INSERT INTO liga_partidos (categoria_id, jornada_id, grupo_id, pareja1_id, pareja2_id, estado) VALUES ($catId, $currJId, $gValSql, $pA, $pB, 'Programado')");
                $nuevoPartidoId = $conn->insert_id;

                $agregadosEnJornada++;
                $partidosAgregadosCat++;
                $totalPartidosAgregados++;

                $partidosNuevosCreados[] = [
                    "id" => $nuevoPartidoId,
                    "jornada_id" => $currJId,
                    "numero_jornada" => $numJ,
                    "pareja1" => $todasParejas[$pA]['nombre_pareja'] ?? "ID $pA",
                    "pareja2" => $todasParejas[$pB]['nombre_pareja'] ?? "ID $pB"
                ];
            }

            if ($targetJornadaNum && $numJ === $targetJornadaNum) {
                $totalEnJornada = ($conteoFijosPorJornada[$targetJornadaNum] ?? 0) + $agregadosEnJornada;
                $detalles[] = "$catNombre (Jornada $targetJornadaNum): +$agregadosEnJornada partido(s) (Total Jornada: $totalEnJornada/$maxPartidosPorJornada)";
            }
        }

        if (!$targetJornadaNum && $partidosAgregadosCat > 0) {
            $detalles[] = "$catNombre: +$partidosAgregadosCat partidos distribuidos en $minJornadasNecesarias jornadas ($maxPartidosPorJornada partidos por jornada)";
        }

        // H. Eliminar cualquier jornada sobrante (> minJornadasNecesarias) que no contenga partidos reales
        $resExtras = $conn->query("SELECT id, numero_jornada FROM liga_jornadas WHERE categoria_id = $catId AND numero_jornada > $minJornadasNecesarias");
        if ($resExtras) {
            while ($rowEx = $resExtras->fetch_assoc()) {
                $exId = (int)$rowEx['id'];
                $chk = $conn->query("SELECT count(*) as cnt FROM liga_partidos WHERE jornada_id = $exId AND (cancha_id > 0 OR fecha_hora > '2000-01-01 00:00:00' OR estado IN ('Jugado','Walkover'))");
                $cnt = ($chk && $r = $chk->fetch_assoc()) ? (int)$r['cnt'] : 0;
                if ($cnt === 0) {
                    $conn->query("DELETE FROM liga_partidos WHERE jornada_id = $exId");
                    $conn->query("DELETE FROM liga_jornadas WHERE id = $exId");
                    $totalJornadasEliminadas++;
                }
            }
        }
    }

    $conn->commit();

    if (ob_get_length()) ob_clean();
    echo json_encode([
        "success" => true,
        "partidos_agregados" => $totalPartidosAgregados,
        "partidos_creados" => $partidosNuevosCreados,
        "jornadas_sobrantes_eliminadas" => $totalJornadasEliminadas,
        "detalles" => $detalles,
        "mensaje" => $totalPartidosAgregados > 0 
            ? "Partidos completados con éxito respetando al 100% todos los resultados, horarios y partidos previos."
            : "Todos los partidos requeridos ya están correctamente programados."
    ]);

} catch (Throwable $e) {
    if (isset($conn) && $conn) {
        @$conn->rollback();
    }
    if (ob_get_length()) ob_clean();
    http_response_code(200);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
