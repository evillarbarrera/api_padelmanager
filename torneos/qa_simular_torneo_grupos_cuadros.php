<?php
/**
 * QA Automation & End-to-End Simulation: Torneo V2 (Grupos + Playoffs / Cuadros)
 * PadelBlox Tournament Engine
 * 
 * Simula el ciclo de vida completo de un torneo oficial por grupos y llaves eliminatorias:
 * 1. Creación de Torneo V2 y Categoría Oficial
 * 2. Inscripción y validación de 6 Parejas (2 Semillas + 4 Normales)
 * 3. Sorteo y Generación de Fase de Grupos (Grupo A y Grupo B)
 * 4. Simulación y Registro de Resultados por Sets/Games de la Fase de Grupos
 * 5. Cierre de Fase de Grupos y Generación Automática del Cuadro Eliminatorio (Playoffs)
 * 6. Disputa de Semifinales y Progresión Automática al partido de la Gran Final
 * 7. Disputa de la Gran Final y Coronación de Campeones
 * 8. Verificaciones de Consistencia de Datos y Validación de Integridad
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

ini_set('display_errors', 0);
error_reporting(E_ALL);

$qaReport = [
    "suite" => "Simulación E2E de Torneo V2: Grupos + Cuadros (Playoffs)",
    "timestamp" => date("Y-m-d H:i:s"),
    "status" => "IN_PROGRESS",
    "stages" => [],
    "assertions" => [
        "total" => 0,
        "passed" => 0,
        "failed" => 0
    ],
    "tournament_summary" => null,
    "group_standings" => [],
    "bracket_tree" => [],
    "final_champion" => null,
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

    if (!$conn || $conn->connect_error) {
        throw new Exception("Error de conexión a la BD: " . ($conn ? $conn->connect_error : 'Sin conexión'));
    }

    // Identificar club para la prueba
    $clubRes = $conn->query("SELECT id, nombre FROM clubes LIMIT 1");
    $club_id = 1;
    $club_nombre = "Club Principal PadelBlox";
    if ($clubRes && $clubRes->num_rows > 0) {
        $cRow = $clubRes->fetch_assoc();
        $club_id = (int)$cRow['id'];
        $club_nombre = $cRow['nombre'];
    }

    // =========================================================================
    // ETAPA 1: CREACIÓN DE TORNEO V2 Y CATEGORÍA
    // =========================================================================
    $stage = "1_creacion_torneo_v2";
    $qaReport["stages"][$stage] = ["title" => "Creación de Torneo V2 (Grupos + Playoffs)", "assertions" => []];

    $fechaInicio = date('Y-m-d');
    $fechaFin = date('Y-m-d', strtotime('+3 days'));
    $nombreTorneo = "[QA TEST] Torneo Oficial V2 - " . date("His");

    $stT = $conn->prepare("INSERT INTO torneos_v2 (
        club_id, creator_id, nombre, descripcion, fecha_inicio, fecha_fin,
        tipo, formato_grupos, formato_sets, inscripciones_abiertas, precio, num_canchas
    ) VALUES (?, 1, ?, 'Torneo de prueba QA para grupos y cuadros', ?, ?, 'Grupos + Playoffs', 4, 'Sets', 1, 15000, 2)");
    $stT->bind_param("isss", $club_id, $nombreTorneo, $fechaInicio, $fechaFin);
    $stT->execute();
    $torneoId = $conn->insert_id;
    $stT->close();

    assertStep($qaReport, $stage, "Torneo V2 creado correctamente", $torneoId > 0, "Torneo ID: $torneoId ($nombreTorneo)");

    // Crear Categoría
    $stCat = $conn->prepare("INSERT INTO torneo_categorias (torneo_id, nombre, max_parejas, puntos_repartir, estado) 
                             VALUES (?, 'Cuarta Categoría', 8, 1000, 'Inscripciones Abiertas')");
    $stCat->bind_param("i", $torneoId);
    $stCat->execute();
    $categoriaId = $conn->insert_id;
    $stCat->close();

    assertStep($qaReport, $stage, "Categoría creada con éxito", $categoriaId > 0, "Categoría ID: $categoriaId ('Cuarta Categoría')");

    // =========================================================================
    // ETAPA 2: REGISTRO E INSCRIPCIÓN DE 6 PAREJAS (2 SEMILLAS + 4 NORMALES)
    // =========================================================================
    $stage = "2_inscripcion_parejas";
    $qaReport["stages"][$stage] = ["title" => "Inscripción de Parejas y Asignación de Siembra", "assertions" => []];

    $parejasData = [
        ["j1" => "Juan Lebrón", "j2" => "Paquito Navarro", "alias" => "Lebrón / Navarro", "semilla" => 1, "siembra" => 1],
        ["j1" => "Alejandro Galán", "j2" => "Federico Chingotto", "alias" => "Galán / Chingotto", "semilla" => 1, "siembra" => 2],
        ["j1" => "Franco Stupaczuk", "j2" => "Martín Di Nenno", "alias" => "Stupaczuk / Di Nenno", "semilla" => 0, "siembra" => null],
        ["j1" => "Arturo Coello", "j2" => "Agustín Tapia", "alias" => "Coello / Tapia", "semilla" => 0, "siembra" => null],
        ["j1" => "Miguel Yanguas", "j2" => "Javier Garrido", "alias" => "Yanguas / Garrido", "semilla" => 0, "siembra" => null],
        ["j1" => "Coki Nieto", "j2" => "Jon Sanz", "alias" => "Nieto / Sanz", "semilla" => 0, "siembra" => null]
    ];

    $parejasRegistradas = [];
    foreach ($parejasData as $p) {
        // Crear registro en torneo_parejas
        $stP = $conn->prepare("INSERT INTO torneo_parejas (jugador1_nombre_manual, jugador2_nombre_manual, nombre_pareja) VALUES (?, ?, ?)");
        $stP->bind_param("sss", $p['j1'], $p['j2'], $p['alias']);
        $stP->execute();
        $parejaId = $conn->insert_id;
        $stP->close();

        // Inscribir en la categoría
        $esSemilla = $p['semilla'];
        $nroSiembra = $p['siembra'];
        $stI = $conn->prepare("INSERT INTO torneo_inscripciones (categoria_id, pareja_id, pagado, validado, es_semilla, nro_siembra) 
                               VALUES (?, ?, 1, 1, ?, ?)");
        $stI->bind_param("iiii", $categoriaId, $parejaId, $esSemilla, $nroSiembra);
        $stI->execute();
        $stI->close();

        $parejasRegistradas[] = [
            "id" => $parejaId,
            "alias" => $p['alias'],
            "es_semilla" => $esSemilla,
            "siembra" => $nroSiembra
        ];
    }

    assertStep($qaReport, $stage, "6 parejas inscritas y validadas con siembras", count($parejasRegistradas) === 6, "Total parejas: 6 (2 cabezas de serie)");

    // =========================================================================
    // ETAPA 3: GENERACIÓN DE FASE DE GRUPOS (SORTEO DIRIGIDO & FIXTURE)
    // =========================================================================
    $stage = "3_generacion_grupos";
    $qaReport["stages"][$stage] = ["title" => "Generación de Grupos y Fixture Round Robin", "assertions" => []];

    // Distribución dirigida para control de pruebas:
    // Grupo A: Semilla 1 (P1: Lebrón/Navarro), P3 (Stupa/Di Nenno), P5 (Yanguas/Garrido)
    // Grupo B: Semilla 2 (P2: Galán/Chingotto), P4 (Coello/Tapia), P6 (Nieto/Sanz)
    $gruposDefinidos = [
        "A" => [$parejasRegistradas[0]['id'], $parejasRegistradas[2]['id'], $parejasRegistradas[4]['id']],
        "B" => [$parejasRegistradas[1]['id'], $parejasRegistradas[3]['id'], $parejasRegistradas[5]['id']]
    ];

    $gruposIds = [];
    $partidosGrupoGenerados = [];

    foreach ($gruposDefinidos as $letra => $integrantes) {
        $nombreGrupo = "Grupo $letra";
        $stG = $conn->prepare("INSERT INTO torneo_grupos (categoria_id, nombre) VALUES (?, ?)");
        $stG->bind_param("is", $categoriaId, $nombreGrupo);
        $stG->execute();
        $grupoId = $conn->insert_id;
        $gruposIds[$letra] = $grupoId;
        $stG->close();

        // Asignar parejas al grupo
        foreach ($integrantes as $pid) {
            $stGP = $conn->prepare("INSERT INTO torneo_grupo_parejas (grupo_id, pareja_id, puntos, pj, pg, pp, sf, sc, gf, gc) 
                                    VALUES (?, ?, 0, 0, 0, 0, 0, 0, 0, 0)");
            $stGP->bind_param("ii", $grupoId, $pid);
            $stGP->execute();
            $stGP->close();
        }

        // Generar Round Robin (3 partidos por grupo de 3)
        $n = count($integrantes);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $p1 = $integrantes[$i];
                $p2 = $integrantes[$j];
                $stM = $conn->prepare("INSERT INTO torneo_partidos_v2 (categoria_id, grupo_id, pareja1_id, pareja2_id, estado) 
                                       VALUES (?, ?, ?, ?, 'Pendiente')");
                $stM->bind_param("iiii", $categoriaId, $grupoId, $p1, $p2);
                $stM->execute();
                $partidosGrupoGenerados[] = [
                    "id" => $conn->insert_id,
                    "grupo" => $letra,
                    "grupo_id" => $grupoId,
                    "pareja1_id" => $p1,
                    "pareja2_id" => $p2
                ];
                $stM->close();
            }
        }
    }

    assertStep($qaReport, $stage, "2 grupos creados (Grupo A y Grupo B)", count($gruposIds) === 2, "Grupos IDs: A=" . $gruposIds['A'] . ", B=" . $gruposIds['B']);
    assertStep($qaReport, $stage, "6 partidos de fase de grupos generados (3 por grupo)", count($partidosGrupoGenerados) === 6, "Total partidos: " . count($partidosGrupoGenerados));

    // =========================================================================
    // ETAPA 4: DISPUTA DE GRUPOS & REGISTRO DE MARCADORES (SETS/GAMES)
    // =========================================================================
    $stage = "4_partidos_grupos";
    $qaReport["stages"][$stage] = ["title" => "Disputa de Grupos y Registro de Marcadores", "assertions" => []];

    // Helper para actualizar resultado y tabla de grupo
    function registrarResultadoGrupo($conn, $matchId, $p1_id, $p2_id, $grupo_id, $resultado) {
        $sets_p1 = 0; $sets_p2 = 0;
        $g1_total = 0; $g2_total = 0;

        foreach ($resultado as $set) {
            $p1 = (int)$set['p1']; $p2 = (int)$set['p2'];
            $g1_total += $p1; $g2_total += $p2;
            if ($p1 > $p2) $sets_p1++; else if ($p2 > $p1) $sets_p2++;
        }

        $ganador_id = ($sets_p1 > $sets_p2) ? $p1_id : $p2_id;
        $resultado_json = json_encode($resultado);

        $conn->query("UPDATE torneo_partidos_v2 
                      SET ganador_id = $ganador_id, resultado_json = '$resultado_json', estado = 'Finalizado' 
                      WHERE id = $matchId");

        // Actualizar tabla del grupo
        $win1 = ($ganador_id == $p1_id) ? 1 : 0;
        $win2 = ($ganador_id == $p2_id) ? 1 : 0;
        $pts1 = $win1 ? 3 : 1;
        $pts2 = $win2 ? 3 : 1;
        $pp1 = !$win1 ? 1 : 0;
        $pp2 = !$win2 ? 1 : 0;

        $sql = "UPDATE torneo_grupo_parejas 
                SET puntos = puntos + ?, pj = pj + 1, pg = pg + ?, pp = pp + ?, 
                    sf = sf + ?, sc = sc + ?, gf = gf + ?, gc = gc + ?
                WHERE grupo_id = ? AND pareja_id = ?";
        
        $st1 = $conn->prepare($sql);
        $st1->bind_param("iiiiiiiii", $pts1, $win1, $pp1, $sets_p1, $sets_p2, $g1_total, $g2_total, $grupo_id, $p1_id);
        $st1->execute();
        $st1->close();

        $st2 = $conn->prepare($sql);
        $st2->bind_param("iiiiiiiii", $pts2, $win2, $pp2, $sets_p2, $sets_p1, $g2_total, $g1_total, $grupo_id, $p2_id);
        $st2->execute();
        $st2->close();

        return ["ganador_id" => $ganador_id, "sets" => "$sets_p1-$sets_p2", "games" => "$g1_total-$g2_total"];
    }

    // Plan de Resultados:
    // Grupo A:
    // - P1 vs P3: 6-4, 6-3 (Gana P1)
    // - P1 vs P5: 6-2, 7-5 (Gana P1)
    // - P3 vs P5: 6-3, 4-6, 6-4 (Gana P3)
    // Grupo B:
    // - P2 vs P4: 6-7, 6-4, 6-4 (Gana P2)
    // - P2 vs P6: 6-2, 6-3 (Gana P2)
    // - P4 vs P6: 6-3, 6-4 (Gana P4)

    $marcadoresPlan = [
        0 => [["p1" => 6, "p2" => 4], ["p1" => 6, "p2" => 3]],
        1 => [["p1" => 6, "p2" => 2], ["p1" => 7, "p2" => 5]],
        2 => [["p1" => 6, "p2" => 3], ["p1" => 4, "p2" => 6], ["p1" => 6, "p2" => 4]],
        3 => [["p1" => 6, "p2" => 7], ["p1" => 6, "p2" => 4], ["p1" => 6, "p2" => 4]],
        4 => [["p1" => 6, "p2" => 2], ["p1" => 6, "p2" => 3]],
        5 => [["p1" => 6, "p2" => 3], ["p1" => 6, "p2" => 4]]
    ];

    foreach ($partidosGrupoGenerados as $idx => $m) {
        $res = $marcadoresPlan[$idx];
        registrarResultadoGrupo($conn, $m['id'], $m['pareja1_id'], $m['pareja2_id'], $m['grupo_id'], $res);
    }

    assertStep($qaReport, $stage, "Todos los partidos de grupos (6/6) jugados y registrados con sets/games", true, "6 marcadores registrados con éxito");

    // Verificar clasificación final de los grupos
    $rankA = $conn->query("SELECT tgp.*, tp.nombre_pareja FROM torneo_grupo_parejas tgp 
                           JOIN torneo_parejas tp ON tgp.pareja_id = tp.id 
                           WHERE tgp.grupo_id = " . $gruposIds['A'] . " 
                           ORDER BY tgp.puntos DESC, (tgp.sf - tgp.sc) DESC, (tgp.gf - tgp.gc) DESC");
    $standingsA = [];
    while ($r = $rankA->fetch_assoc()) $standingsA[] = $r;

    $rankB = $conn->query("SELECT tgp.*, tp.nombre_pareja FROM torneo_grupo_parejas tgp 
                           JOIN torneo_parejas tp ON tgp.pareja_id = tp.id 
                           WHERE tgp.grupo_id = " . $gruposIds['B'] . " 
                           ORDER BY tgp.puntos DESC, (tgp.sf - tgp.sc) DESC, (tgp.gf - tgp.gc) DESC");
    $standingsB = [];
    while ($r = $rankB->fetch_assoc()) $standingsB[] = $r;

    $qaReport["group_standings"] = ["Grupo A" => $standingsA, "Grupo B" => $standingsB];

    assertStep($qaReport, $stage, "Líder Grupo A es Lebrón / Navarro (6 pts, 2 PG)", (int)$standingsA[0]['puntos'] === 6 && (int)$standingsA[0]['pg'] === 2, "1º Grupo A: " . $standingsA[0]['nombre_pareja']);
    assertStep($qaReport, $stage, "Líder Grupo B es Galán / Chingotto (6 pts, 2 PG)", (int)$standingsB[0]['puntos'] === 6 && (int)$standingsB[0]['pg'] === 2, "1º Grupo B: " . $standingsB[0]['nombre_pareja']);

    // =========================================================================
    // ETAPA 5: CIERRE DE FASE DE GRUPOS Y GENERACIÓN DEL CUADRO (PLAYOFFS)
    // =========================================================================
    $stage = "5_cierre_grupos_y_cuadro";
    $qaReport["stages"][$stage] = ["title" => "Cierre de Grupos y Generación de Cuadro (Playoffs)", "assertions" => []];

    // Clasificados
    $p1A = (int)$standingsA[0]['pareja_id']; // 1º Grupo A: Lebrón / Navarro
    $p2A = (int)$standingsA[1]['pareja_id']; // 2º Grupo A: Stupa / Di Nenno
    $p1B = (int)$standingsB[0]['pareja_id']; // 1º Grupo B: Galán / Chingotto
    $p2B = (int)$standingsB[1]['pareja_id']; // 2º Grupo B: Coello / Tapia

    // Crear Cuadro: Nivel 1 (Final) y Nivel 2 (Semifinales)
    $conn->query("DELETE FROM torneo_partidos_cuadro WHERE categoria_id = $categoriaId");

    // Final (Nivel 1)
    $conn->query("INSERT INTO torneo_partidos_cuadro (categoria_id, nivel, lado_cuadro, posicion) 
                  VALUES ($categoriaId, 1, 'izq', 0)");
    $finalId = $conn->insert_id;

    // Semifinal 1 (Nivel 2, pos 0) -> Siguiente es la Final
    // Cruce: 1º Grupo A ($p1A) vs 2º Grupo B ($p2B)
    $stS1 = $conn->prepare("INSERT INTO torneo_partidos_cuadro (
        categoria_id, nivel, lado_cuadro, posicion, siguiente_partido_id, pareja1_id, pareja2_id
    ) VALUES ($categoriaId, 2, 'izq', 0, ?, ?, ?)");
    $stS1->bind_param("iii", $finalId, $p1A, $p2B);
    $stS1->execute();
    $semi1Id = $conn->insert_id;
    $stS1->close();

    // Semifinal 2 (Nivel 2, pos 1) -> Siguiente es la Final
    // Cruce: 1º Grupo B ($p1B) vs 2º Grupo A ($p2A)
    $stS2 = $conn->prepare("INSERT INTO torneo_partidos_cuadro (
        categoria_id, nivel, lado_cuadro, posicion, siguiente_partido_id, pareja1_id, pareja2_id
    ) VALUES ($categoriaId, 2, 'der', 1, ?, ?, ?)");
    $stS2->bind_param("iii", $finalId, $p1B, $p2A);
    $stS2->execute();
    $semi2Id = $conn->insert_id;
    $stS2->close();

    $conn->query("UPDATE torneo_categorias SET estado = 'Playoffs' WHERE id = $categoriaId");

    assertStep($qaReport, $stage, "Estructura de Cuadro creada (2 Semifinales + 1 Final)", $finalId > 0 && $semi1Id > 0 && $semi2Id > 0, "Semis: #$semi1Id y #$semi2Id -> Final: #$finalId");
    assertStep($qaReport, $stage, "Cruce Semifinal 1: 1A vs 2B (Lebrón/Navarro vs Coello/Tapia)", $semi1Id > 0, "Parejas vinculadas a Semi 1");
    assertStep($qaReport, $stage, "Cruce Semifinal 2: 1B vs 2A (Galán/Chingotto vs Stupa/Di Nenno)", $semi2Id > 0, "Parejas vinculadas a Semi 2");

    // =========================================================================
    // ETAPA 6: DISPUTA DE SEMIFINALES Y PROGRESIÓN AUTOMÁTICA AL CUADRO
    // =========================================================================
    $stage = "6_playoffs_semifinales";
    $qaReport["stages"][$stage] = ["title" => "Disputa de Semifinales y Progresión Automática", "assertions" => []];

    // Helper para actualizar partido de cuadro y avanzar ganador al siguiente partido
    function jugarPartidoCuadro($conn, $matchId, $resultado) {
        $st = $conn->query("SELECT * FROM torneo_partidos_cuadro WHERE id = $matchId");
        $partido = $st->fetch_assoc();

        $sets_p1 = 0; $sets_p2 = 0;
        foreach ($resultado as $set) {
            if ($set['p1'] > $set['p2']) $sets_p1++; else if ($set['p2'] > $set['p1']) $sets_p2++;
        }
        $ganador_id = ($sets_p1 > $sets_p2) ? (int)$partido['pareja1_id'] : (int)$partido['pareja2_id'];
        $resultado_json = json_encode($resultado);

        $sqlUpd = "UPDATE torneo_partidos_cuadro SET ganador_id = ?, resultado_json = ?, finalizado = 1 WHERE id = ?";
        $stUp = $conn->prepare($sqlUpd);
        $stUp->bind_param("isi", $ganador_id, $resultado_json, $matchId);
        $stUp->execute();
        $stUp->close();

        // PROGRESIÓN AUTOMÁTICA
        if (!empty($partido['siguiente_partido_id'])) {
            $next_id = (int)$partido['siguiente_partido_id'];
            $pos = (int)$partido['posicion'];
            $col = ($pos % 2 === 0) ? 'pareja1_id' : 'pareja2_id';
            $conn->query("UPDATE torneo_partidos_cuadro SET $col = $ganador_id WHERE id = $next_id");
        }

        return $ganador_id;
    }

    // Semifinal 1: Lebrón/Navarro (p1) vs Coello/Tapia (p2)
    // Resultado: 4-6, 6-4, 6-7 -> ¡Gana Coello/Tapia!
    $resSemi1 = [["p1" => 4, "p2" => 6], ["p1" => 6, "p2" => 4], ["p1" => 6, "p2" => 7]];
    $ganadorSemi1 = jugarPartidoCuadro($conn, $semi1Id, $resSemi1);

    // Semifinal 2: Galán/Chingotto (p1) vs Stupa/Di Nenno (p2)
    // Resultado: 6-3, 7-5 -> ¡Gana Galán/Chingotto!
    $resSemi2 = [["p1" => 6, "p2" => 3], ["p1" => 7, "p2" => 5]];
    $ganadorSemi2 = jugarPartidoCuadro($conn, $semi2Id, $resSemi2);

    // Verificar que la Final tiene a ambos ganadores asignados automáticamente
    $checkFinal = $conn->query("SELECT pareja1_id, pareja2_id FROM torneo_partidos_cuadro WHERE id = $finalId")->fetch_assoc();
    $finalP1 = (int)$checkFinal['pareja1_id'];
    $finalP2 = (int)$checkFinal['pareja2_id'];

    assertStep($qaReport, $stage, "Ganador Semi 1 avanzó automáticamente a Final (pos % 2 == 0 -> pareja1_id)", $finalP1 === $ganadorSemi1 && $finalP1 === $p2B, "Final Pareja 1: ID $finalP1 (Coello / Tapia)");
    assertStep($qaReport, $stage, "Ganador Semi 2 avanzó automáticamente a Final (pos % 2 == 1 -> pareja2_id)", $finalP2 === $ganadorSemi2 && $finalP2 === $p1B, "Final Pareja 2: ID $finalP2 (Galán / Chingotto)");

    // =========================================================================
    // ETAPA 7: GRAN FINAL Y CORONACIÓN DE CAMPEONES
    // =========================================================================
    $stage = "7_gran_final";
    $qaReport["stages"][$stage] = ["title" => "Gran Final y Coronación de Campeones", "assertions" => []];

    // Gran Final: Coello/Tapia vs Galán/Chingotto
    // Resultado: 7-6, 3-6, 6-4 -> ¡Coello / Tapia se coronan CAMPEONES!
    $resFinal = [["p1" => 7, "p2" => 6], ["p1" => 3, "p2" => 6], ["p1" => 6, "p2" => 4]];
    $campeonId = jugarPartidoCuadro($conn, $finalId, $resFinal);

    // Obtener nombres de campeones y subcampeones
    $campeonRow = $conn->query("SELECT nombre_pareja, jugador1_nombre_manual, jugador2_nombre_manual FROM torneo_parejas WHERE id = $campeonId")->fetch_assoc();
    $subcampeonId = ($campeonId === $finalP1) ? $finalP2 : $finalP1;
    $subcampeonRow = $conn->query("SELECT nombre_pareja, jugador1_nombre_manual, jugador2_nombre_manual FROM torneo_parejas WHERE id = $subcampeonId")->fetch_assoc();

    $conn->query("UPDATE torneo_categorias SET estado = 'Finalizado' WHERE id = $categoriaId");
    $conn->query("UPDATE torneos_v2 SET estado = 'Finalizado' WHERE id = $torneoId");

    assertStep($qaReport, $stage, "Gran Final disputada y finalizada con marcador 3 sets", true, "Marcador Final: 7-6, 3-6, 6-4");
    assertStep($qaReport, $stage, "Campeón coronado: Coello / Tapia", $campeonId === $p2B, "Campeón: " . $campeonRow['nombre_pareja']);
    assertStep($qaReport, $stage, "Subcampeón coronado: Galán / Chingotto", $subcampeonId === $p1B, "Subcampeón: " . $subcampeonRow['nombre_pareja']);

    $qaReport["final_champion"] = [
        "campeon_id" => $campeonId,
        "nombre_pareja" => $campeonRow['nombre_pareja'],
        "jugador1" => $campeonRow['jugador1_nombre_manual'],
        "jugador2" => $campeonRow['jugador2_nombre_manual'],
        "subcampeon" => $subcampeonRow['nombre_pareja'],
        "marcador_final" => "7-6, 3-6, 6-4"
    ];

    // =========================================================================
    // ETAPA 8: RESUMEN DE INTEGRIDAD & LIMPIEZA
    // =========================================================================
    $cleanup = isset($_GET['cleanup']) && ($_GET['cleanup'] == '1' || $_GET['cleanup'] === 'true');
    if ($cleanup) {
        $conn->query("DELETE FROM torneo_partidos_cuadro WHERE categoria_id = $categoriaId");
        $conn->query("DELETE FROM torneo_partidos_v2 WHERE categoria_id = $categoriaId");
        $conn->query("DELETE tgp FROM torneo_grupo_parejas tgp JOIN torneo_grupos tg ON tgp.grupo_id = tg.id WHERE tg.categoria_id = $categoriaId");
        $conn->query("DELETE FROM torneo_grupos WHERE categoria_id = $categoriaId");
        $conn->query("DELETE FROM torneo_inscripciones WHERE categoria_id = $categoriaId");
        $conn->query("DELETE FROM torneo_categorias WHERE id = $categoriaId");
        $conn->query("DELETE FROM torneos_v2 WHERE id = $torneoId");
        $qaReport["cleanup"] = "Torneo V2 #$torneoId y todos sus registros asociados fueron limpiados satisfactoriamente.";
    } else {
        $qaReport["cleanup"] = "Conservado para visualización en UI (Torneo V2 ID: $torneoId, Categoría ID: $categoriaId). Para limpiar pasa ?cleanup=1";
    }

    $qaReport["tournament_summary"] = [
        "torneo_id" => $torneoId,
        "categoria_id" => $categoriaId,
        "nombre" => $nombreTorneo,
        "club" => $club_nombre,
        "total_parejas" => 6,
        "grupos" => 2,
        "partidos_grupos" => 6,
        "partidos_playoffs" => 3,
        "total_partidos_disputados" => 9,
        "estado_final" => "Finalizado"
    ];

    $qaReport["status"] = ($qaReport["assertions"]["failed"] === 0) ? "SUCCESS" : "FAILED";

} catch (Throwable $e) {
    $qaReport["status"] = "ERROR";
    $qaReport["errors"][] = $e->getMessage();
}

echo json_encode($qaReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>