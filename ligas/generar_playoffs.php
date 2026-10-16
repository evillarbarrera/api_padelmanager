<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
mysqli_report(MYSQLI_REPORT_OFF);
require_once "../db.php";
require_once "posiciones_helper.php";

$input = json_decode(file_get_contents("php://input"), true);
$categoria_id = !empty($input['categoria_id']) ? (int)$input['categoria_id'] : null;
$num_clasificados = !empty($input['num_clasificados']) ? (int)$input['num_clasificados'] : 8;
$directos_cuartos = isset($input['directos_cuartos']) ? (int)$input['directos_cuartos'] : -1;

if (!$categoria_id) {
    echo json_encode(["success" => false, "error" => "ID de categoría requerido"]);
    exit;
}

try {
    $conn->begin_transaction();

    // Asegurar que las columnas acepten NULL en llaves pendientes
    @$conn->query("ALTER TABLE liga_partidos MODIFY `pareja1_id` INT NULL DEFAULT NULL");
    @$conn->query("ALTER TABLE liga_partidos MODIFY `pareja2_id` INT NULL DEFAULT NULL");

    // 0. Recalcular posiciones de la categoría para garantizar datos 100% frescos
    recalcularPosicionesCategoria($conn, $categoria_id);

    // 1. Obtener la tabla de posiciones consolidada de la categoría
    $posList = obtenerPosicionesArray($conn, $categoria_id);
    $clasificados = [];
    foreach ($posList as $row) {
        $clasificados[] = [
            'id' => (int)$row['id'],
            'nombre' => $row['nombre_pareja'],
            'puntos' => (int)$row['puntos'],
            'dif_sets' => (int)$row['dif_sets'],
            'dif_games' => (int)$row['dif_games']
        ];
    }

    $total = count($clasificados);
    if ($total < 2) {
        throw new Exception("Se necesitan al menos 2 parejas confirmadas en la categoría para generar Playoffs.");
    }

    // Ajustar total clasificados si se pidieron más de los existentes o "todos"
    if ($num_clasificados >= 999 || $num_clasificados > $total) {
        $num_clasificados = $total;
    }

    // Validar y ajustar pases directos a cuartos
    if ($directos_cuartos < 0) {
        // Por defecto: Si hay 12 o más y son 12, 4 directos; si son 8, 0 directos
        if ($num_clasificados == 12) {
            $directos_cuartos = 4;
        } elseif ($num_clasificados == 10) {
            $directos_cuartos = 6;
        } elseif ($num_clasificados == 14) {
            $directos_cuartos = 2;
        } else {
            $directos_cuartos = ($num_clasificados > 8) ? 4 : 0;
        }
    }

    if ($num_clasificados <= 8) {
        $directos_cuartos = 0;
    }

    // Si hay directos a cuartos, la cantidad que va a previa debe ser par
    $previa_count = $num_clasificados - $directos_cuartos;
    if ($previa_count % 2 !== 0) {
        $num_clasificados = $num_clasificados - 1;
        $previa_count = $num_clasificados - $directos_cuartos;
    }

    // Limitar clasificados exactos
    $clasificados = array_slice($clasificados, 0, $num_clasificados);
    $n = count($clasificados);

    // Limpiar partidos de playoff anteriores de esta categoría
    $conn->query("DELETE FROM liga_partidos WHERE categoria_id = $categoria_id AND es_playoff = 1");

    // Guardar configuración en la categoría
    @$conn->query("UPDATE liga_categorias SET parejas_clasifican_playoff = $n WHERE id = $categoria_id");

    $crucesDetalle = [];

    // Helper para insertar partido
    $insertMatch = function($p1_id, $p2_id, $ronda, $obs) use ($conn, $categoria_id) {
        $obsJson = is_array($obs) ? json_encode($obs, JSON_UNESCAPED_UNICODE) : $obs;
        $stmt = $conn->prepare("INSERT INTO liga_partidos (categoria_id, pareja1_id, pareja2_id, estado, es_playoff, ronda_playoff, observaciones) VALUES (?, ?, ?, 'Programado', 1, ?, ?)");
        $p1 = $p1_id ?: null;
        $p2 = $p2_id ?: null;
        $stmt->bind_param("iiiss", $categoria_id, $p1, $p2, $ronda, $obsJson);
        $stmt->execute();
        return $conn->insert_id;
    };

    // ==========================================
    // CASO 1: CUADRO CON RONDA PREVIA / OCTAVOS (N > 8 con BYEs a 4tos o 16 completos)
    // ==========================================
    if ($n > 8 && $directos_cuartos > 0) {
        $ronda_inicial = "Octavos de Final";
        $p_previa = ($n - $directos_cuartos) / 2; // Partidos de previa necesarios

        // Previa Matches (ej. con B=4, N=12 -> 4 partidos con 5º al 12º)
        // 1º al B-ésimo están exentos (pasan directo)
        $previaTeams = array_slice($clasificados, $directos_cuartos); // Parejas que juegan previa
        $numPrevia = count($previaTeams); // 8 parejas para B=4, N=12

        // Parejas directas a Cuartos
        $d1 = $clasificados[0]; // 1º
        $d2 = ($directos_cuartos >= 2) ? $clasificados[1] : null; // 2º
        $d3 = ($directos_cuartos >= 3) ? $clasificados[2] : null; // 3º
        $d4 = ($directos_cuartos >= 4) ? $clasificados[3] : null; // 4º
        $d5 = ($directos_cuartos >= 5) ? $clasificados[4] : null; // 5º
        $d6 = ($directos_cuartos >= 6) ? $clasificados[5] : null; // 6º

        // Insertar Partidos de Previa (Octavos)
        // Matchups: Best seed of previa vs Worst seed of previa
        // PREV1 (Rival de 1º en Q1): 8º vs 9º (o correspondiente)
        // PREV2 (Rival de 4º en Q2): 5º vs 12º
        // PREV3 (Rival de 3º en Q3): 6º vs 11º
        // PREV4 (Rival de 2º en Q4): 7º vs 10º
        
        $prevMatchesConfig = [];
        if ($directos_cuartos == 4 && $n == 12) {
            // Estándar 12 parejas (4 directos, 4 partidos de previa):
            // 8º vs 9º -> Q1
            // 5º vs 12º -> Q2
            // 6º vs 11º -> Q3
            // 7º vs 10º -> Q4
            $prevMatchesConfig = [
                ['t1' => $clasificados[7], 'pos1' => 8, 't2' => $clasificados[8], 'pos2' => 9, 'code' => 'PREV1', 'next' => 'Q1', 'slot' => 2, 'title' => 'Previa 1 (8º vs 9º)'],
                ['t1' => $clasificados[4], 'pos1' => 5, 't2' => $clasificados[11], 'pos2' => 12, 'code' => 'PREV2', 'next' => 'Q2', 'slot' => 2, 'title' => 'Previa 2 (5º vs 12º)'],
                ['t1' => $clasificados[5], 'pos1' => 6, 't2' => $clasificados[10], 'pos2' => 11, 'code' => 'PREV3', 'next' => 'Q3', 'slot' => 2, 'title' => 'Previa 3 (6º vs 11º)'],
                ['t1' => $clasificados[6], 'pos1' => 7, 't2' => $clasificados[9], 'pos2' => 10, 'code' => 'PREV4', 'next' => 'Q4', 'slot' => 2, 'title' => 'Previa 4 (7º vs 10º)'],
            ];
        } elseif ($directos_cuartos == 6 && $n == 10) {
            // 6 directos (1º al 6º), 2 partidos de previa (7º al 10º):
            // 8º vs 9º -> Q1 (contra 1º)
            // 7º vs 10º -> Q4 (contra 2º)
            $prevMatchesConfig = [
                ['t1' => $clasificados[7], 'pos1' => 8, 't2' => $clasificados[8], 'pos2' => 9, 'code' => 'PREV1', 'next' => 'Q1', 'slot' => 2, 'title' => 'Previa 1 (8º vs 9º)'],
                ['t1' => $clasificados[6], 'pos1' => 7, 't2' => $clasificados[9], 'pos2' => 10, 'code' => 'PREV4', 'next' => 'Q4', 'slot' => 2, 'title' => 'Previa 2 (7º vs 10º)'],
            ];
        } else {
            // Genérico: emparejar los extremos de previa
            for ($k = 0; $k < $p_previa; $k++) {
                $idx1 = $directos_cuartos + $k;
                $idx2 = $n - 1 - $k;
                $t1 = $clasificados[$idx1];
                $t2 = $clasificados[$idx2];
                $code = 'PREV' . ($k + 1);
                $targetQ = 'Q' . (($k % 4) + 1);
                $prevMatchesConfig[] = [
                    't1' => $t1, 'pos1' => $idx1 + 1,
                    't2' => $t2, 'pos2' => $idx2 + 1,
                    'code' => $code, 'next' => $targetQ, 'slot' => 2,
                    'title' => "Previa " . ($k + 1) . " (" . ($idx1 + 1) . "º vs " . ($idx2 + 1) . "º)"
                ];
            }
        }

        foreach ($prevMatchesConfig as $pm) {
            $obs = [
                'bracket_code' => $pm['code'],
                'next_bracket' => $pm['next'],
                'next_slot' => $pm['slot'],
                'seed1_label' => "{$pm['pos1']}º {$pm['t1']['nombre']}",
                'seed2_label' => "{$pm['pos2']}º {$pm['t2']['nombre']}"
            ];
            $insertMatch($pm['t1']['id'], $pm['t2']['id'], 'Octavos de Final', $obs);
            $crucesDetalle[] = [
                "ronda" => "Octavos de Final (Previa)",
                "pareja1" => "{$pm['pos1']}º {$pm['t1']['nombre']}",
                "pareja2" => "{$pm['pos2']}º {$pm['t2']['nombre']}",
                "destino" => "Avanza a Cuartos ({$pm['next']})"
            ];
        }

        // Insertar Cuartos de Final (Q1, Q2, Q3, Q4)
        // Q1: 1º vs Ganador PREV1
        $insertMatch($d1['id'], null, 'Cuartos de Final', [
            'bracket_code' => 'Q1', 'next_bracket' => 'S1', 'next_slot' => 1,
            'seed1_label' => "1º {$d1['nombre']}", 'seed2_label' => "Ganador Previa 1"
        ]);
        $crucesDetalle[] = [
            "ronda" => "Cuartos de Final (Q1)",
            "pareja1" => "1º {$d1['nombre']} (BYE Directo)",
            "pareja2" => "Ganador Previa 1"
        ];

        // Q2: 4º (o Directo) vs Ganador PREV2 (o 5º)
        $q2_p1 = ($d4 ? $d4['id'] : null);
        $q2_p2 = ($d5 ? $d5['id'] : null);
        $insertMatch($q2_p1, $q2_p2, 'Cuartos de Final', [
            'bracket_code' => 'Q2', 'next_bracket' => 'S1', 'next_slot' => 2,
            'seed1_label' => $d4 ? "4º {$d4['nombre']}" : "Ganador Previa 2A",
            'seed2_label' => $d5 ? "5º {$d5['nombre']}" : "Ganador Previa 2"
        ]);
        $crucesDetalle[] = [
            "ronda" => "Cuartos de Final (Q2)",
            "pareja1" => $d4 ? "4º {$d4['nombre']} (BYE Directo)" : "Por definir",
            "pareja2" => $d5 ? "5º {$d5['nombre']} (BYE Directo)" : "Ganador Previa 2"
        ];

        // Q3: 3º (o Directo) vs Ganador PREV3 (o 6º)
        $q3_p1 = ($d3 ? $d3['id'] : null);
        $q3_p2 = ($d6 ? $d6['id'] : null);
        $insertMatch($q3_p1, $q3_p2, 'Cuartos de Final', [
            'bracket_code' => 'Q3', 'next_bracket' => 'S2', 'next_slot' => 1,
            'seed1_label' => $d3 ? "3º {$d3['nombre']}" : "Ganador Previa 3A",
            'seed2_label' => $d6 ? "6º {$d6['nombre']}" : "Ganador Previa 3"
        ]);
        $crucesDetalle[] = [
            "ronda" => "Cuartos de Final (Q3)",
            "pareja1" => $d3 ? "3º {$d3['nombre']} (BYE Directo)" : "Por definir",
            "pareja2" => $d6 ? "6º {$d6['nombre']} (BYE Directo)" : "Ganador Previa 3"
        ];

        // Q4: 2º vs Ganador PREV4
        $insertMatch($d2['id'], null, 'Cuartos de Final', [
            'bracket_code' => 'Q4', 'next_bracket' => 'S2', 'next_slot' => 2,
            'seed1_label' => "2º {$d2['nombre']}", 'seed2_label' => "Ganador Previa 4"
        ]);
        $crucesDetalle[] = [
            "ronda" => "Cuartos de Final (Q4)",
            "pareja1" => "2º {$d2['nombre']} (BYE Directo)",
            "pareja2" => "Ganador Previa 4"
        ];

        // Semifinales (S1, S2)
        $insertMatch(null, null, 'Semifinales', [
            'bracket_code' => 'S1', 'next_bracket' => 'F1', 'next_slot' => 1,
            'seed1_label' => 'Ganador Cuartos 1', 'seed2_label' => 'Ganador Cuartos 2'
        ]);
        $insertMatch(null, null, 'Semifinales', [
            'bracket_code' => 'S2', 'next_bracket' => 'F1', 'next_slot' => 2,
            'seed1_label' => 'Ganador Cuartos 3', 'seed2_label' => 'Ganador Cuartos 4'
        ]);

        // Final (F1)
        $insertMatch(null, null, 'Final', [
            'bracket_code' => 'F1',
            'seed1_label' => 'Ganador Semifinal 1', 'seed2_label' => 'Ganador Semifinal 2'
        ]);

    } elseif ($n >= 8) {
        // ==========================================
        // CASO 2: TOP 8 DIRECTO A CUARTOS (Sin BYE)
        // ==========================================
        $ronda_inicial = "Cuartos de Final";
        $insertMatch($clasificados[0]['id'], $clasificados[7]['id'], 'Cuartos de Final', [
            'bracket_code' => 'Q1', 'next_bracket' => 'S1', 'next_slot' => 1,
            'seed1_label' => "1º {$clasificados[0]['nombre']}", 'seed2_label' => "8º {$clasificados[7]['nombre']}"
        ]);
        $insertMatch($clasificados[3]['id'], $clasificados[4]['id'], 'Cuartos de Final', [
            'bracket_code' => 'Q2', 'next_bracket' => 'S1', 'next_slot' => 2,
            'seed1_label' => "4º {$clasificados[3]['nombre']}", 'seed2_label' => "5º {$clasificados[4]['nombre']}"
        ]);
        $insertMatch($clasificados[2]['id'], $clasificados[5]['id'], 'Cuartos de Final', [
            'bracket_code' => 'Q3', 'next_bracket' => 'S2', 'next_slot' => 1,
            'seed1_label' => "3º {$clasificados[2]['nombre']}", 'seed2_label' => "6º {$clasificados[5]['nombre']}"
        ]);
        $insertMatch($clasificados[1]['id'], $clasificados[6]['id'], 'Cuartos de Final', [
            'bracket_code' => 'Q4', 'next_bracket' => 'S2', 'next_slot' => 2,
            'seed1_label' => "2º {$clasificados[1]['nombre']}", 'seed2_label' => "7º {$clasificados[6]['nombre']}"
        ]);

        $insertMatch(null, null, 'Semifinales', [
            'bracket_code' => 'S1', 'next_bracket' => 'F1', 'next_slot' => 1,
            'seed1_label' => 'Ganador Cuartos 1', 'seed2_label' => 'Ganador Cuartos 2'
        ]);
        $insertMatch(null, null, 'Semifinales', [
            'bracket_code' => 'S2', 'next_bracket' => 'F1', 'next_slot' => 2,
            'seed1_label' => 'Ganador Cuartos 3', 'seed2_label' => 'Ganador Cuartos 4'
        ]);
        $insertMatch(null, null, 'Final', [
            'bracket_code' => 'F1',
            'seed1_label' => 'Ganador Semifinal 1', 'seed2_label' => 'Ganador Semifinal 2'
        ]);

        $crucesDetalle = [
            ["ronda" => "Cuartos", "pareja1" => "1º {$clasificados[0]['nombre']}", "pareja2" => "8º {$clasificados[7]['nombre']}"],
            ["ronda" => "Cuartos", "pareja1" => "4º {$clasificados[3]['nombre']}", "pareja2" => "5º {$clasificados[4]['nombre']}"],
            ["ronda" => "Cuartos", "pareja1" => "3º {$clasificados[2]['nombre']}", "pareja2" => "6º {$clasificados[5]['nombre']}"],
            ["ronda" => "Cuartos", "pareja1" => "2º {$clasificados[1]['nombre']}", "pareja2" => "7º {$clasificados[6]['nombre']}"]
        ];

    } elseif ($n >= 4) {
        // ==========================================
        // CASO 3: TOP 4 DIRECTO A SEMIFINALES
        // ==========================================
        $ronda_inicial = "Semifinales";
        $insertMatch($clasificados[0]['id'], $clasificados[3]['id'], 'Semifinales', [
            'bracket_code' => 'S1', 'next_bracket' => 'F1', 'next_slot' => 1,
            'seed1_label' => "1º {$clasificados[0]['nombre']}", 'seed2_label' => "4º {$clasificados[3]['nombre']}"
        ]);
        $insertMatch($clasificados[1]['id'], $clasificados[2]['id'], 'Semifinales', [
            'bracket_code' => 'S2', 'next_bracket' => 'F1', 'next_slot' => 2,
            'seed1_label' => "2º {$clasificados[1]['nombre']}", 'seed2_label' => "3º {$clasificados[2]['nombre']}"
        ]);
        $insertMatch(null, null, 'Final', [
            'bracket_code' => 'F1',
            'seed1_label' => 'Ganador Semifinal 1', 'seed2_label' => 'Ganador Semifinal 2'
        ]);
        $crucesDetalle = [
            ["ronda" => "Semifinal", "pareja1" => "1º {$clasificados[0]['nombre']}", "pareja2" => "4º {$clasificados[3]['nombre']}"],
            ["ronda" => "Semifinal", "pareja1" => "2º {$clasificados[1]['nombre']}", "pareja2" => "3º {$clasificados[2]['nombre']}"]
        ];
    } else {
        // ==========================================
        // CASO 4: 2 PAREJAS DIRECTAS A FINAL
        // ==========================================
        $ronda_inicial = "Final";
        $insertMatch($clasificados[0]['id'], $clasificados[1]['id'], 'Final', [
            'bracket_code' => 'F1',
            'seed1_label' => "1º {$clasificados[0]['nombre']}", 'seed2_label' => "2º {$clasificados[1]['nombre']}"
        ]);
        $crucesDetalle = [
            ["ronda" => "Final", "pareja1" => "1º {$clasificados[0]['nombre']}", "pareja2" => "2º {$clasificados[1]['nombre']}"]
        ];
    }

    // ==========================================
    // CUADRO OPCIONAL DE CONSOLACIÓN (COPA DE PLATA)
    // ==========================================
    $generar_consolacion = !empty($input['generar_consolacion']) ? (bool)$input['generar_consolacion'] : false;
    $consolacionGenerada = false;

    if ($generar_consolacion && ($total - $n) >= 2) {
        $consolacionPairs = array_slice($posList, $n);
        $nc = count($consolacionPairs);
        if ($nc >= 4) {
            $insertMatch($consolacionPairs[0]['id'], $consolacionPairs[3]['id'], 'Consolación - Semifinales', [
                'bracket_code' => 'CS1', 'next_bracket' => 'CF1', 'next_slot' => 1, 'cuadro' => 'plata',
                'seed1_label' => "5º {$consolacionPairs[0]['nombre_pareja']}", 'seed2_label' => "8º {$consolacionPairs[3]['nombre_pareja']}"
            ]);
            $insertMatch($consolacionPairs[1]['id'], $consolacionPairs[2]['id'], 'Consolación - Semifinales', [
                'bracket_code' => 'CS2', 'next_bracket' => 'CF1', 'next_slot' => 2, 'cuadro' => 'plata',
                'seed1_label' => "6º {$consolacionPairs[1]['nombre_pareja']}", 'seed2_label' => "7º {$consolacionPairs[2]['nombre_pareja']}"
            ]);
            $insertMatch(null, null, 'Consolación - Final', [
                'bracket_code' => 'CF1', 'cuadro' => 'plata',
                'seed1_label' => 'Ganador Semifinal Plata 1', 'seed2_label' => 'Ganador Semifinal Plata 2'
            ]);
            $consolacionGenerada = true;
        } elseif ($nc >= 2) {
            $insertMatch($consolacionPairs[0]['id'], $consolacionPairs[1]['id'], 'Consolación - Final', [
                'bracket_code' => 'CF1', 'cuadro' => 'plata',
                'seed1_label' => "5º {$consolacionPairs[0]['nombre_pareja']}", 'seed2_label' => "6º {$consolacionPairs[1]['nombre_pareja']}"
            ]);
            $consolacionGenerada = true;
        }
    }

    // Actualizar estado de la liga a 'Playoffs'
    $conn->query("UPDATE ligas l 
                  JOIN liga_categorias c ON c.liga_id = l.id 
                  SET l.estado = 'Playoffs' 
                  WHERE c.id = $categoria_id");

    $conn->commit();

    echo json_encode([
        "success" => true,
        "mensaje" => "Cuadro de Playoffs generado con $n parejas ($directos_cuartos clasificados directamente a Cuartos de Final).",
        "ronda_inicial" => $ronda_inicial,
        "parejas_clasificadas" => $n,
        "directos_cuartos" => $directos_cuartos,
        "cruces" => $crucesDetalle
    ]);

} catch (Throwable $e) {
    if (isset($conn) && $conn) {
        @$conn->rollback();
    }
    http_response_code(200);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
