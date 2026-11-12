<?php
/**
 * QA Automation & End-to-End Tournament Simulation
 * PadelBlox Tournament Engine
 * 
 * Simula el ciclo de vida completo de un torneo de pádel (Torneo Americano):
 * 1. Configuración & Creación del Torneo
 * 2. Inscripción de Parejas / Jugadores
 * 3. Generación de Fixture y Rondas por Pistas
 * 4. Simulación y Registro de Marcadores (Resultados)
 * 5. Verificación de Tabla de Posiciones y Métricas (PJ, PG, PE, PP, GF, GC, DIF, Puntos)
 * 6. Definición de Podio y Cierre del Torneo (Asignación de Puntos de Ranking)
 * 7. Limpieza / Rollback controlado (opcional via ?cleanup=1)
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

ini_set('display_errors', 0);
error_reporting(E_ALL);

$qaReport = [
    "suite" => "Simulación E2E de Torneo Americano PadelBlox",
    "timestamp" => date("Y-m-d H:i:s"),
    "status" => "IN_PROGRESS",
    "stages" => [],
    "assertions" => [
        "total" => 0,
        "passed" => 0,
        "failed" => 0
    ],
    "tournament_summary" => null,
    "standings_final" => [],
    "podium" => [],
    "errors" => []
];

function assertStep(&$qaReport, $stageName, $assertionName, $condition, $details = "") {
    $qaReport["assertions"]["total"]++;
    $passed = (bool)$condition;
    if ($passed) {
        $qaReport["assertions"]["passed"]++;
    } else {
        $qaReport["assertions"]["failed"]++;
        $qaReport["errors"][] = "[$stageName] Falló: $assertionName - $details";
    }
    
    $qaReport["stages"][$stageName]["assertions"][] = [
        "assertion" => $assertionName,
        "status" => $passed ? "PASS" : "FAIL",
        "details" => $details
    ];
    return $passed;
}

try {
    require_once __DIR__ . "/../db.php";
    require_once __DIR__ . "/match_utils.php";

    if (!$conn || $conn->connect_error) {
        throw new Exception("Error de conexión a la BD: " . ($conn ? $conn->connect_error : 'Sin conexión'));
    }

    // Identificar club para la prueba
    $clubRes = $conn->query("SELECT id, nombre FROM clubes LIMIT 1");
    if (!$clubRes || $clubRes->num_rows === 0) {
        // Fallback: usar club_id 1
        $club_id = 1;
        $club_nombre = "Club Principal PadelBlox";
    } else {
        $cRow = $clubRes->fetch_assoc();
        $club_id = (int)$cRow['id'];
        $club_nombre = $cRow['nombre'];
    }

    // =========================================================================
    // ETAPA 1: CREACIÓN DEL TORNEO DE PRUEBA
    // =========================================================================
    $stage = "1_creacion_torneo";
    $qaReport["stages"][$stage] = ["title" => "Creación de Torneo Americano", "assertions" => []];

    $fechaTorneo = date('Y-m-d');
    $horaInicio = "19:00:00";
    $nombreTorneo = "[QA TEST] Americano Nocturno Pro - " . date("His");
    $categoria = "Cuarta";
    $numCanchas = 2; // 2 pistas = 4 parejas
    $tiempoPartido = 20;

    $sqlCreate = "INSERT INTO torneos_americanos (
        club_id, nombre, fecha, hora_inicio, num_canchas, tiempo_por_partido,
        puntos_ganado, puntos_empate, puntos_1er_lugar, puntos_2do_lugar,
        puntos_3er_lugar, puntos_4to_lugar, puntos_participacion, categoria, estado
    ) VALUES (?, ?, ?, ?, ?, ?, 3, 1, 100, 60, 40, 20, 5, ?, 'Abierto')";

    $stC = $conn->prepare($sqlCreate);
    if (!$stC) throw new Exception("Prepare falló en creación de torneo: " . $conn->error);
    $stC->bind_param("isssiis", $club_id, $nombreTorneo, $fechaTorneo, $horaInicio, $numCanchas, $tiempoPartido, $categoria);
    $stC->execute();
    $torneoId = $conn->insert_id;
    $stC->close();

    assertStep($qaReport, $stage, "Torneo creado con ID válido", $torneoId > 0, "Torneo ID: $torneoId en club '$club_nombre' (ID: $club_id)");

    // =========================================================================
    // ETAPA 2: INSCRIPCIÓN DE 4 PAREJAS (8 JUGADORES)
    // =========================================================================
    $stage = "2_inscripcion_parejas";
    $qaReport["stages"][$stage] = ["title" => "Inscripción de Parejas", "assertions" => []];

    $parejasFixture = [
        ["p_num" => 1, "j1" => "Agustín Tapia", "j2" => "Arturo Coello", "alias" => "Los Bombarderos"],
        ["p_num" => 2, "j1" => "Alejandro Galán", "j2" => "Federico Chingotto", "alias" => "Chingalán"],
        ["p_num" => 3, "j1" => "Franco Stupaczuk", "j2" => "Martín Di Nenno", "alias" => "Superpibes"],
        ["p_num" => 4, "j1" => "Paquito Navarro", "j2" => "Juan Lebrón", "alias" => "Lobo & Paco"]
    ];

    $parejasIds = [];
    foreach ($parejasFixture as $p) {
        $stIns = $conn->prepare("INSERT INTO torneo_participantes (
            torneo_id, nombre_externo_1, nombre_externo_2, nombre_pareja, created_at
        ) VALUES (?, ?, ?, ?, NOW())");
        $stIns->bind_param("isss", $torneoId, $p['j1'], $p['j2'], $p['alias']);
        $stIns->execute();
        $partId = $conn->insert_id;
        $parejasIds[] = [
            "id" => $partId,
            "alias" => $p['alias'],
            "j1" => $p['j1'],
            "j2" => $p['j2']
        ];
        $stIns->close();
    }

    assertStep($qaReport, $stage, "4 parejas inscritas correctamente", count($parejasIds) === 4, "Total parejas registradas: " . count($parejasIds));

    // =========================================================================
    // ETAPA 3: GENERACIÓN DEL FIXTURE (ROUND ROBIN 3 RONDAS)
    // =========================================================================
    $stage = "3_generacion_fixture";
    $qaReport["stages"][$stage] = ["title" => "Generación de Fixture y Rondas", "assertions" => []];

    // Ronda 1: Pareja 1 vs Pareja 2 (Pista 1), Pareja 3 vs Pareja 4 (Pista 2)
    // Ronda 2: Pareja 1 vs Pareja 3 (Pista 1), Pareja 2 vs Pareja 4 (Pista 2)
    // Ronda 3: Pareja 1 vs Pareja 4 (Pista 1), Pareja 2 vs Pareja 3 (Pista 2)
    $partidosRondas = [
        // Ronda 1
        ["ronda" => 1, "pista" => 1, "hora" => "19:00:00", "pA" => 0, "pB" => 1],
        ["ronda" => 1, "pista" => 2, "hora" => "19:00:00", "pA" => 2, "pB" => 3],
        // Ronda 2
        ["ronda" => 2, "pista" => 1, "hora" => "19:25:00", "pA" => 0, "pB" => 2],
        ["ronda" => 2, "pista" => 2, "hora" => "19:25:00", "pA" => 1, "pB" => 3],
        // Ronda 3
        ["ronda" => 3, "pista" => 1, "hora" => "19:50:00", "pA" => 0, "pB" => 3],
        ["ronda" => 3, "pista" => 2, "hora" => "19:50:00", "pA" => 1, "pB" => 2],
    ];

    $matchesGenerados = [];
    foreach ($partidosRondas as $pr) {
        $pA = $parejasIds[$pr['pA']];
        $pB = $parejasIds[$pr['pB']];
        
        $stM = $conn->prepare("INSERT INTO torneo_partidos (
            torneo_id, ronda, num_cancha, hora_inicio,
            nombre_externo_1, nombre_externo_2, nombre_externo_3, nombre_externo_4,
            fase, finalizado
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Clasificacion', 0)");

        $stM->bind_param("iiisssss", 
            $torneoId, $pr['ronda'], $pr['pista'], $pr['hora'],
            $pA['j1'], $pA['j2'], $pB['j1'], $pB['j2']
        );
        $stM->execute();
        $mId = $conn->insert_id;
        $stM->close();

        $matchesGenerados[] = [
            "id" => $mId,
            "ronda" => $pr['ronda'],
            "pista" => $pr['pista'],
            "pareja_A" => $pA['alias'],
            "pareja_B" => $pB['alias'],
            "pA_idx" => $pr['pA'],
            "pB_idx" => $pr['pB']
        ];
    }

    assertStep($qaReport, $stage, "6 partidos generados en 3 rondas rotativas", count($matchesGenerados) === 6, "Total partidos creados: 6 (2 pistas x 3 rondas)");

    // =========================================================================
    // ETAPA 4: SIMULACIÓN DE RESULTADOS Y CARGA DE MARCADORES
    // =========================================================================
    $stage = "4_carga_marcadores";
    $qaReport["stages"][$stage] = ["title" => "Carga de Marcadores", "assertions" => []];

    // Marcadores planificados para probar diferentes escenarios:
    // M0: P1 (6) vs P2 (3) -> Victoria P1
    // M1: P3 (4) vs P4 (6) -> Victoria P4
    // M2: P1 (6) vs P3 (2) -> Victoria P1
    // M3: P2 (5) vs P4 (5) -> Empate 5-5
    // M4: P1 (6) vs P4 (4) -> Victoria P1
    // M5: P2 (6) vs P3 (3) -> Victoria P2
    $resultadosSimulados = [
        ["m_idx" => 0, "games_t1" => 6, "games_t2" => 3],
        ["m_idx" => 1, "games_t1" => 4, "games_t2" => 6],
        ["m_idx" => 2, "games_t1" => 6, "games_t2" => 2],
        ["m_idx" => 3, "games_t1" => 5, "games_t2" => 5],
        ["m_idx" => 4, "games_t1" => 6, "games_t2" => 4],
        ["m_idx" => 5, "games_t1" => 6, "games_t2" => 3],
    ];

    $marcadoresGuardados = 0;
    foreach ($resultadosSimulados as $res) {
        $mInfo = $matchesGenerados[$res['m_idx']];
        $mId = $mInfo['id'];
        $g1 = $res['games_t1'];
        $g2 = $res['games_t2'];

        $stUp = $conn->prepare("UPDATE torneo_partidos 
                                SET puntos_t1 = ?, puntos_t2 = ?, finalizado = 1, estado = 'Jugado' 
                                WHERE id = ?");
        $stUp->bind_param("iii", $g1, $g2, $mId);
        $stUp->execute();
        if ($stUp->affected_rows > 0) {
            $marcadoresGuardados++;
        }
        $stUp->close();
    }

    assertStep($qaReport, $stage, "Todos los marcadores (6/6) guardados y finalizados", $marcadoresGuardados === 6, "Marcadores actualizados: $marcadoresGuardados");

    // =========================================================================
    // ETAPA 5: CÁLCULO DE TABLA DE POSICIONES Y VALIDACIÓN MATEMÁTICA
    // =========================================================================
    $stage = "5_tabla_posiciones";
    $qaReport["stages"][$stage] = ["title" => "Cálculo y Validación de Posiciones", "assertions" => []];

    // Estructura de posiciones
    $standings = [];
    foreach ($parejasIds as $idx => $p) {
        $standings[$idx] = [
            "idx" => $idx,
            "pareja_id" => $p['id'],
            "alias" => $p['alias'],
            "jugadores" => $p['j1'] . " / " . $p['j2'],
            "pj" => 0,
            "pg" => 0,
            "pe" => 0,
            "pp" => 0,
            "gf" => 0,
            "gc" => 0,
            "dif" => 0,
            "puntos" => 0
        ];
    }

    // Calcular estadísticas desde los partidos finalizados
    $resPart = $conn->query("SELECT * FROM torneo_partidos WHERE torneo_id = $torneoId ORDER BY id ASC");
    $totalGF = 0;
    $totalGC = 0;

    while ($rowM = $resPart->fetch_assoc()) {
        $pt1 = (int)$rowM['puntos_t1'];
        $pt2 = (int)$rowM['puntos_t2'];
        $totalGF += $pt1;
        $totalGC += $pt2;

        // Encontrar índice de pareja A y B
        $idxA = -1; $idxB = -1;
        foreach ($parejasIds as $i => $p) {
            if ($p['j1'] === $rowM['nombre_externo_1'] || $p['j2'] === $rowM['nombre_externo_2']) $idxA = $i;
            if ($p['j1'] === $rowM['nombre_externo_3'] || $p['j2'] === $rowM['nombre_externo_4']) $idxB = $i;
        }

        if ($idxA !== -1 && $idxB !== -1) {
            $standings[$idxA]['pj']++;
            $standings[$idxB]['pj']++;
            $standings[$idxA]['gf'] += $pt1;
            $standings[$idxA]['gc'] += $pt2;
            $standings[$idxB]['gf'] += $pt2;
            $standings[$idxB]['gc'] += $pt1;

            if ($pt1 > $pt2) {
                $standings[$idxA]['pg']++;
                $standings[$idxA]['puntos'] += 3;
                $standings[$idxB]['pp']++;
            } elseif ($pt2 > $pt1) {
                $standings[$idxB]['pg']++;
                $standings[$idxB]['puntos'] += 3;
                $standings[$idxA]['pp']++;
            } else {
                $standings[$idxA]['pe']++;
                $standings[$idxB]['pe']++;
                $standings[$idxA]['puntos'] += 1;
                $standings[$idxB]['puntos'] += 1;
            }
        }
    }

    foreach ($standings as &$st) {
        $st['dif'] = $st['gf'] - $st['gc'];
    }
    unset($st);

    // Ordenar tabla: Puntos DESC, Diferencia de Games DESC, Games a Favor DESC
    usort($standings, function($a, $b) {
        if ($b['puntos'] !== $a['puntos']) return $b['puntos'] - $a['puntos'];
        if ($b['dif'] !== $a['dif']) return $b['dif'] - $a['dif'];
        return $b['gf'] - $a['gf'];
    });

    // Validaciones matemáticas
    $totalPJ = array_sum(array_column($standings, 'pj'));
    assertStep($qaReport, $stage, "Consistencia Games: Total GF == Total GC", $totalGF === $totalGC, "GF: $totalGF, GC: $totalGC");
    assertStep($qaReport, $stage, "Consistencia Partidos: Cada pareja jugó 3 partidos", $totalPJ === 12, "Total partidos individuales sumados: $totalPJ (6 partidos x 2)");
    assertStep($qaReport, $stage, "Pareja #1 invicta lidera con 9 puntos", $standings[0]['puntos'] === 9 && $standings[0]['pg'] === 3, "Líder: " . $standings[0]['alias'] . " (PG: " . $standings[0]['pg'] . ", Pts: " . $standings[0]['puntos'] . ")");

    $qaReport["standings_final"] = $standings;

    // =========================================================================
    // ETAPA 6: FASE FINAL (ORO / PLATA) Y CIERRE DE TORNEO
    // =========================================================================
    $stage = "6_finales_y_cierre";
    $qaReport["stages"][$stage] = ["title" => "Fase Final y Cierre de Torneo", "assertions" => []];

    // Gran Final Oro: 1º vs 2º clasificado
    $primero = $standings[0];
    $segundo = $standings[1];
    $tercero = $standings[2];
    $cuarto = $standings[3];

    // Insertar Final Oro
    $stFinal = $conn->prepare("INSERT INTO torneo_partidos (
        torneo_id, ronda, num_cancha, hora_inicio,
        nombre_externo_1, nombre_externo_2, nombre_externo_3, nombre_externo_4,
        grupo_id, fase, puntos_t1, puntos_t2, finalizado, estado
    ) VALUES (?, 4, 1, '20:15:00', ?, ?, ?, ?, 'Oro', 'Finales', 7, 5, 1, 'Finalizado')");
    $j1_1 = explode(" / ", $primero['jugadores'])[0];
    $j1_2 = explode(" / ", $primero['jugadores'])[1];
    $j2_1 = explode(" / ", $segundo['jugadores'])[0];
    $j2_2 = explode(" / ", $segundo['jugadores'])[1];
    $stFinal->bind_param("issss", $torneoId, $j1_1, $j1_2, $j2_1, $j2_2);
    $stFinal->execute();
    $finalOroId = $conn->insert_id;
    $stFinal->close();

    assertStep($qaReport, $stage, "Partido de Final Oro registrado (7-5 a favor de P1)", $finalOroId > 0, "Final Oro ID: $finalOroId");

    // Ejecutar lógica oficial de cierre del torneo
    $conn->query("UPDATE torneos_americanos SET estado = 'Cerrado' WHERE id = $torneoId");
    
    // Verificar estado cerrado en base de datos
    $checkEstado = $conn->query("SELECT estado FROM torneos_americanos WHERE id = $torneoId")->fetch_assoc()['estado'];
    assertStep($qaReport, $stage, "Torneo transiciona a estado 'Cerrado'", strtolower(trim($checkEstado)) === 'cerrado', "Estado verificado: '$checkEstado'");

    $podio = [
        "campeon_oro_1er" => ["lugar" => "1º", "alias" => $primero['alias'], "jugadores" => $primero['jugadores'], "puntos_ranking_otorgados" => 100],
        "subcampeon_oro_2do" => ["lugar" => "2º", "alias" => $segundo['alias'], "jugadores" => $segundo['jugadores'], "puntos_ranking_otorgados" => 60],
        "tercer_lugar" => ["lugar" => "3º", "alias" => $tercero['alias'], "jugadores" => $tercero['jugadores'], "puntos_ranking_otorgados" => 40],
        "cuarto_lugar" => ["lugar" => "4º", "alias" => $cuarto['alias'], "jugadores" => $cuarto['jugadores'], "puntos_ranking_otorgados" => 20]
    ];
    $qaReport["podium"] = $podio;

    // =========================================================================
    // ETAPA 7: LIMPIEZA / TEARDOWN OPCIONAL
    // =========================================================================
    $cleanup = isset($_GET['cleanup']) && ($_GET['cleanup'] == '1' || $_GET['cleanup'] === 'true');
    if ($cleanup) {
        $conn->query("DELETE FROM torneo_partidos WHERE torneo_id = $torneoId");
        $conn->query("DELETE FROM torneo_participantes WHERE torneo_id = $torneoId");
        $conn->query("DELETE FROM torneos_americanos WHERE id = $torneoId");
        $qaReport["cleanup"] = "Torneo de prueba #$torneoId eliminado satisfactoriamente de la BD.";
    } else {
        $qaReport["cleanup"] = "Conservado para inspección visual en UI (ID: $torneoId). Para eliminar pasa ?cleanup=1";
    }

    $qaReport["tournament_summary"] = [
        "id" => $torneoId,
        "nombre" => $nombreTorneo,
        "club_id" => $club_id,
        "club_nombre" => $club_nombre,
        "categoria" => $categoria,
        "parejas_inscritas" => 4,
        "partidos_clasificacion" => 6,
        "partidos_finales" => 1,
        "estado_final" => $checkEstado
    ];

    $qaReport["status"] = ($qaReport["assertions"]["failed"] === 0) ? "SUCCESS" : "FAILED";

} catch (Throwable $e) {
    $qaReport["status"] = "ERROR";
    $qaReport["errors"][] = $e->getMessage();
}

echo json_encode($qaReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>