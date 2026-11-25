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

    // Validar y ajustar pases directos a la siguiente fase:
    // - Si N > 16: Pases directos son a Octavos (32 - N por FIP). Los 8 partidos de Octavos se juegan hacia Cuartos.
    // - Si 9 <= N <= 16: Pases directos son a Cuartos (16 - N por FIP).
    // - Si 5 <= N <= 8: Pases directos son a Semifinales (8 - N por FIP).
    // - Si N <= 4: Semifinales / Final.
    if ($num_clasificados <= 8) {
        $directos_cuartos = 0;
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

    if ($n > 16) {
        // ==========================================
        // CASO 1: DIECISEISAVOS DE FINAL (17 a 32 parejas)
        // Los BYEs van DIRECTOS A OCTAVOS (nadie pasa directo a Cuartos desde 16avos)
        // ==========================================
        $ronda_inicial = "Dieciseisavos de Final";
        $fipOptimo = max(0, 32 - $n);
        $directos_octavos = ($directos_cuartos < 0) ? $fipOptimo : max((int)$directos_cuartos, $fipOptimo);
        $directos_octavos = min(15, $directos_octavos);

        $num16avos = 16 - $directos_octavos;
        $directToOctavosCount = $directos_octavos;

        $octavosEntries = [];
        for ($i = 0; $i < $directToOctavosCount; $i++) {
            $t = $clasificados[$i];
            $seedNum = $i + 1;
            $octavosEntries[] = [
                'id' => $t['id'],
                'seed' => $seedNum,
                'nombre' => "{$seedNum}º {$t['nombre']}",
                'is_playin' => false,
                'is_bye' => true
            ];
        }

        $playInTeams = array_slice($clasificados, $directToOctavosCount, 2 * $num16avos);
        $playInMatchesCreated = [];

        for ($pIdx = 0; $pIdx < $num16avos; $pIdx++) {
            $tA = $playInTeams[$pIdx];
            $tB = $playInTeams[count($playInTeams) - 1 - $pIdx];
            $sA = $directToOctavosCount + $pIdx + 1;
            $sB = $n - $pIdx;
            $piCode = 'PI' . ($pIdx + 1);
            $octavosEntries[] = [
                'id' => null,
                'seed' => $sA,
                'nombre' => "Ganador 16avos " . ($pIdx + 1) . " ({$sA}º vs {$sB}º)",
                'is_playin' => true,
                'pi_code' => $piCode,
                'pi_label' => "{$sA}º/{$sB}º"
            ];
            $playInMatchesCreated[] = [
                'code' => $piCode,
                'p1_id' => $tA['id'],
                'p2_id' => $tB['id'],
                's1_label' => "{$sA}º {$tA['nombre']}",
                's2_label' => "{$sB}º {$tB['nombre']}",
                'next_code' => 'PREV1',
                'next_slot' => 2
            ];
        }

        $directOct = array_values(array_filter($octavosEntries, function($e) { return empty($e['is_playin']); }));
        $playInOct = array_reverse(array_values(array_filter($octavosEntries, function($e) { return !empty($e['is_playin']); })));
        $orderedOctavosSlots = array_merge($directOct, $playInOct);

        $octavosConfig = [
            ['code' => 'PREV1', 'q' => 'Q1', 'slot' => 1],
            ['code' => 'PREV2', 'q' => 'Q1', 'slot' => 2],
            ['code' => 'PREV3', 'q' => 'Q2', 'slot' => 1],
            ['code' => 'PREV4', 'q' => 'Q2', 'slot' => 2],
            ['code' => 'PREV5', 'q' => 'Q3', 'slot' => 1],
            ['code' => 'PREV6', 'q' => 'Q3', 'slot' => 2],
            ['code' => 'PREV7', 'q' => 'Q4', 'slot' => 1],
            ['code' => 'PREV8', 'q' => 'Q4', 'slot' => 2],
        ];

        $quartersMap = [
            'Q1' => ['p1_id' => null, 's1' => 'Ganador Octavos 1', 'p2_id' => null, 's2' => 'Ganador Octavos 2', 'next' => 'S1', 'slot' => 1],
            'Q2' => ['p1_id' => null, 's1' => 'Ganador Octavos 3', 'p2_id' => null, 's2' => 'Ganador Octavos 4', 'next' => 'S1', 'slot' => 2],
            'Q3' => ['p1_id' => null, 's1' => 'Ganador Octavos 5', 'p2_id' => null, 's2' => 'Ganador Octavos 6', 'next' => 'S2', 'slot' => 1],
            'Q4' => ['p1_id' => null, 's1' => 'Ganador Octavos 7', 'p2_id' => null, 's2' => 'Ganador Octavos 8', 'next' => 'S2', 'slot' => 2],
        ];

        $octavosToInsert = [];
        for ($k = 0; $k < 8; $k++) {
            $cfg = $octavosConfig[$k];
            $entry1 = $orderedOctavosSlots[$k];
            $entry2 = $orderedOctavosSlots[15 - $k];

            if (!empty($entry1['is_playin'])) {
                foreach ($playInMatchesCreated as &$pim) {
                    if ($pim['code'] === $entry1['pi_code']) { $pim['next_code'] = $cfg['code']; $pim['next_slot'] = 1; }
                }
                unset($pim);
            }
            if (!empty($entry2['is_playin'])) {
                foreach ($playInMatchesCreated as &$pim) {
                    if ($pim['code'] === $entry2['pi_code']) { $pim['next_code'] = $cfg['code']; $pim['next_slot'] = 2; }
                }
                unset($pim);
            }

            $s1Short = !empty($entry1['is_playin']) ? "16avos ({$entry1['pi_label']})" : "{$entry1['seed']}º";
            $s2Short = !empty($entry2['is_playin']) ? "16avos ({$entry2['pi_label']})" : "{$entry2['seed']}º";
            $winnerLabel = "Ganador Octavos " . ($k + 1) . " ({$s1Short} vs {$s2Short})";

            if ($cfg['slot'] === 1) {
                $quartersMap[$cfg['q']]['s1'] = $winnerLabel;
            } else {
                $quartersMap[$cfg['q']]['s2'] = $winnerLabel;
            }

            $octavosToInsert[] = [
                'p1_id' => $entry1['id'],
                'p2_id' => $entry2['id'],
                'target_q' => $cfg['q'],
                'obs' => [
                    'bracket_code' => $cfg['code'],
                    'next_bracket' => $cfg['q'],
                    'next_slot' => $cfg['slot'],
                    'seed1_label' => $entry1['nombre'],
                    'seed2_label' => $entry2['nombre'],
                    'seed1_is_bye' => !empty($entry1['is_bye']),
                    'seed2_is_bye' => !empty($entry2['is_bye'])
                ]
            ];
        }

        // Insertar partidos de Dieciseisavos
        foreach ($playInMatchesCreated as $pim) {
            $insertMatch($pim['p1_id'], $pim['p2_id'], 'Dieciseisavos de Final', [
                'bracket_code' => $pim['code'],
                'next_bracket' => $pim['next_code'],
                'next_slot' => $pim['next_slot'],
                'seed1_label' => $pim['s1_label'],
                'seed2_label' => $pim['s2_label']
            ]);
            $crucesDetalle[] = [
                "ronda" => "Dieciseisavos de Final",
                "pareja1" => $pim['s1_label'],
                "pareja2" => $pim['s2_label'],
                "destino" => "Avanza a Octavos ({$pim['next_code']})"
            ];
        }

        // Insertar partidos de Octavos
        foreach ($octavosToInsert as $oct) {
            $insertMatch($oct['p1_id'], $oct['p2_id'], 'Octavos de Final', $oct['obs']);
            $crucesDetalle[] = [
                "ronda" => "Octavos de Final",
                "pareja1" => $oct['obs']['seed1_label'],
                "pareja2" => $oct['obs']['seed2_label'],
                "destino" => "Avanza a Cuartos ({$oct['target_q']})"
            ];
        }

        // Insertar los 4 Cuartos de Final (Q1, Q2, Q3, Q4)
        foreach (['Q1', 'Q2', 'Q3', 'Q4'] as $qCode) {
            $qm = $quartersMap[$qCode];
            $insertMatch(null, null, 'Cuartos de Final', [
                'bracket_code' => $qCode,
                'next_bracket' => $qm['next'],
                'next_slot' => $qm['slot'],
                'seed1_label' => $qm['s1'],
                'seed2_label' => $qm['s2']
            ]);
            $crucesDetalle[] = [
                "ronda" => "Cuartos de Final ($qCode)",
                "pareja1" => $qm['s1'],
                "pareja2" => $qm['s2']
            ];
        }

        // Semifinales y Final
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

    } elseif ($n > 8) {
        // ==========================================
        // CASO 2: OCTAVOS DE FINAL (9 a 16 parejas)
        // Los BYEs van DIRECTOS A CUARTOS (4tos)
        // ==========================================
        $ronda_inicial = "Octavos de Final";
        $fipOptimo = 16 - $n;
        $directos_cuartos = ($directos_cuartos < 0) ? $fipOptimo : max((int)$directos_cuartos, $fipOptimo);
        $directos_cuartos = min(7, $directos_cuartos);

        $qOpenSlots = 8 - $directos_cuartos;
        $octavosCapacity = $qOpenSlots * 2;
        $remainingCount = $n - $directos_cuartos;

        if ($remainingCount < $octavosCapacity) {
            $directos_cuartos = max(0, 16 - $n);
            $qOpenSlots = 8 - $directos_cuartos;
            $octavosCapacity = $qOpenSlots * 2;
            $remainingCount = $n - $directos_cuartos;
        }

        $numPlayIn = max(0, $remainingCount - $octavosCapacity);
        $ronda_inicial = ($numPlayIn > 0) ? "Dieciseisavos de Final" : "Octavos de Final";

        $directToOctavosCount = $octavosCapacity - $numPlayIn;
        $octavosEntries = [];
        for ($i = 0; $i < $directToOctavosCount; $i++) {
            $t = $clasificados[$directos_cuartos + $i];
            $seedNum = $directos_cuartos + $i + 1;
            $octavosEntries[] = [
                'id' => $t['id'],
                'seed' => $seedNum,
                'nombre' => "{$seedNum}º {$t['nombre']}",
                'is_playin' => false
            ];
        }

        $playInStartIdx = $directos_cuartos + $directToOctavosCount;
        $playInTeams = array_slice($clasificados, $playInStartIdx);
        $playInMatchesCreated = [];

        for ($pIdx = 0; $pIdx < $numPlayIn; $pIdx++) {
            $tA = $playInTeams[$pIdx];
            $tB = $playInTeams[count($playInTeams) - 1 - $pIdx];
            $sA = $playInStartIdx + $pIdx + 1;
            $sB = $n - $pIdx;
            $piCode = 'PI' . ($pIdx + 1);
            $octavosEntries[] = [
                'id' => null,
                'seed' => $sA,
                'nombre' => "Ganador 16avos " . ($pIdx + 1) . " ({$sA}º vs {$sB}º)",
                'is_playin' => true,
                'pi_code' => $piCode,
                'pi_label' => "{$sA}º/{$sB}º"
            ];
            $playInMatchesCreated[] = [
                'code' => $piCode,
                'p1_id' => $tA['id'],
                'p2_id' => $tB['id'],
                's1_label' => "{$sA}º {$tA['nombre']}",
                's2_label' => "{$sB}º {$tB['nombre']}",
                'next_code' => 'PREV1',
                'next_slot' => 2
            ];
        }

        $directOct = array_values(array_filter($octavosEntries, function($e) { return !$e['is_playin']; }));
        $playInOct = array_reverse(array_values(array_filter($octavosEntries, function($e) { return $e['is_playin']; })));
        $orderedOctavosSlots = array_merge($directOct, $playInOct);

        // Mapa de posiciones de Cuartos para los seeds 1 al 8:
        $quarterSlotForTop8 = [
            1 => ['q' => 'Q1', 'slot' => 1],
            2 => ['q' => 'Q4', 'slot' => 1],
            3 => ['q' => 'Q3', 'slot' => 1],
            4 => ['q' => 'Q2', 'slot' => 1],
            5 => ['q' => 'Q2', 'slot' => 2],
            6 => ['q' => 'Q3', 'slot' => 2],
            7 => ['q' => 'Q4', 'slot' => 2],
            8 => ['q' => 'Q1', 'slot' => 2],
        ];

        $quartersMap = [
            'Q1' => ['p1_id' => null, 's1' => 'Por definir', 'p2_id' => null, 's2' => 'Por definir', 'next' => 'S1', 'slot' => 1],
            'Q2' => ['p1_id' => null, 's1' => 'Por definir', 'p2_id' => null, 's2' => 'Por definir', 'next' => 'S1', 'slot' => 2],
            'Q3' => ['p1_id' => null, 's1' => 'Por definir', 'p2_id' => null, 's2' => 'Por definir', 'next' => 'S2', 'slot' => 1],
            'Q4' => ['p1_id' => null, 's1' => 'Por definir', 'p2_id' => null, 's2' => 'Por definir', 'next' => 'S2', 'slot' => 2],
        ];

        // 1. Asignar BYEs directos a Cuartos (seeds 1..directos_cuartos)
        for ($s = 1; $s <= $directos_cuartos; $s++) {
            $team = $clasificados[$s - 1];
            $target = $quarterSlotForTop8[$s];
            if ($target['slot'] === 1) {
                $quartersMap[$target['q']]['p1_id'] = $team['id'];
                $quartersMap[$target['q']]['s1'] = "{$s}º {$team['nombre']}";
            } else {
                $quartersMap[$target['q']]['p2_id'] = $team['id'];
                $quartersMap[$target['q']]['s2'] = "{$s}º {$team['nombre']}";
            }
        }

        // 2. Preparar Partidos de Octavos / Previa
        $octavosToInsert = [];
        for ($k = 0; $k < $qOpenSlots; $k++) {
            $top8SlotNum = $directos_cuartos + 1 + $k;
            $entry1 = $orderedOctavosSlots[$k];
            $entry2 = $orderedOctavosSlots[count($orderedOctavosSlots) - 1 - $k];
            $target = $quarterSlotForTop8[$top8SlotNum] ?? ['q' => 'Q1', 'slot' => 2];
            $prevNum = $k + 1;
            $code = 'PREV' . $prevNum;

            if (!empty($entry1['is_playin'])) {
                foreach ($playInMatchesCreated as &$pim) {
                    if ($pim['code'] === $entry1['pi_code']) { $pim['next_code'] = $code; $pim['next_slot'] = 1; }
                }
                unset($pim);
            }
            if (!empty($entry2['is_playin'])) {
                foreach ($playInMatchesCreated as &$pim) {
                    if ($pim['code'] === $entry2['pi_code']) { $pim['next_code'] = $code; $pim['next_slot'] = 2; }
                }
                unset($pim);
            }

            $octavosToInsert[] = [
                'p1_id' => $entry1['id'],
                'p2_id' => $entry2['id'],
                'obs' => [
                    'bracket_code' => $code,
                    'next_bracket' => $target['q'],
                    'next_slot' => $target['slot'],
                    'seed1_label' => $entry1['nombre'],
                    'seed2_label' => $entry2['nombre']
                ],
                'target_q' => $target['q']
            ];

            $s1Short = !empty($entry1['is_playin']) ? "16avos ({$entry1['pi_label']})" : "{$entry1['seed']}º";
            $s2Short = !empty($entry2['is_playin']) ? "16avos ({$entry2['pi_label']})" : "{$entry2['seed']}º";
            $winnerLabel = "Ganador Octavos $prevNum ($s1Short vs $s2Short)";
            if ($target['slot'] === 1) {
                $quartersMap[$target['q']]['s1'] = $winnerLabel;
            } else {
                $quartersMap[$target['q']]['s2'] = $winnerLabel;
            }
        }

        // Insertar primero los partidos de Dieciseisavos de Final
        foreach ($playInMatchesCreated as $pim) {
            $insertMatch($pim['p1_id'], $pim['p2_id'], 'Dieciseisavos de Final', [
                'bracket_code' => $pim['code'],
                'next_bracket' => $pim['next_code'],
                'next_slot' => $pim['next_slot'],
                'seed1_label' => $pim['s1_label'],
                'seed2_label' => $pim['s2_label']
            ]);
            $crucesDetalle[] = [
                "ronda" => "Dieciseisavos de Final",
                "pareja1" => $pim['s1_label'],
                "pareja2" => $pim['s2_label'],
                "destino" => "Avanza a Octavos ({$pim['next_code']})"
            ];
        }

        // Insertar partidos de Octavos
        foreach ($octavosToInsert as $oct) {
            $insertMatch($oct['p1_id'], $oct['p2_id'], 'Octavos de Final', $oct['obs']);
            $crucesDetalle[] = [
                "ronda" => "Octavos de Final (Previa)",
                "pareja1" => $oct['obs']['seed1_label'],
                "pareja2" => $oct['obs']['seed2_label'],
                "destino" => "Avanza a Cuartos ({$oct['target_q']})"
            ];
        }

        // 3. Insertar los 4 Cuartos de Final (Q1, Q2, Q3, Q4)
        foreach (['Q1', 'Q2', 'Q3', 'Q4'] as $qCode) {
            $qm = $quartersMap[$qCode];
            $insertMatch($qm['p1_id'], $qm['p2_id'], 'Cuartos de Final', [
                'bracket_code' => $qCode,
                'next_bracket' => $qm['next'],
                'next_slot' => $qm['slot'],
                'seed1_label' => $qm['s1'],
                'seed2_label' => $qm['s2']
            ]);
            $crucesDetalle[] = [
                "ronda" => "Cuartos de Final ($qCode)",
                "pareja1" => $qm['s1'],
                "pareja2" => $qm['s2']
            ];
        }

        // 4. Semifinales (S1, S2) y Final (F1)
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
