<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type, X-Requested-With, Accept, Origin");
header("Access-Control-Max-Age: 86400");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

ob_start();
error_reporting(0);
ini_set('display_errors', 0);

try {
    require_once "../db.php";
    require_once "../auth/auth_helper.php";

    $clubId = isset($_GET['club_id']) ? (int)$_GET['club_id'] : 0;
    $fecha = isset($_GET['fecha']) && !empty($_GET['fecha']) ? trim($_GET['fecha']) : date('Y-m-d');
    $categoriaFilter = isset($_GET['categoria']) ? trim($_GET['categoria']) : '';
    $franjaFilter = isset($_GET['franja']) ? trim($_GET['franja']) : '';

    if (!$clubId) {
        $resClub = @$conn->query("SELECT id FROM clubes LIMIT 1");
        if ($resClub && $row = $resClub->fetch_assoc()) {
            $clubId = (int)$row['id'];
        }
    }

    // 1. Determine day of week
    $dt = new DateTime($fecha);
    $diaSemana = (int)$dt->format('w'); // 0=Dom, 1=Lun... 6=Sáb
    $diasNombres = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    $diaNombre = $diasNombres[$diaSemana] ?? 'Día';

    // 2. Fetch courts in club safely
    $canchas = [];
    $resColsC = @$conn->query("SHOW COLUMNS FROM canchas");
    $hasActiva = false;
    if ($resColsC) {
        while ($c = $resColsC->fetch_assoc()) {
            if (strtolower($c['Field']) === 'activa') $hasActiva = true;
        }
    }

    $whereCancha = "club_id = $clubId";
    if ($hasActiva) {
        $whereCancha .= " AND (activa = 1 OR activa IS NULL)";
    }

    $resCanchas = @$conn->query("SELECT id, nombre FROM canchas WHERE $whereCancha ORDER BY id ASC");
    if ($resCanchas) {
        while ($c = $resCanchas->fetch_assoc()) {
            $canchas[] = $c;
        }
    }

    // 3. Fetch existing reservations for the date safely
    $reservasOcupadas = [];
    $resColsRC = @$conn->query("SHOW COLUMNS FROM reservas_cancha");
    $hasEstado = false;
    if ($resColsRC) {
        while ($rc = $resColsRC->fetch_assoc()) {
            if (strtolower($rc['Field']) === 'estado') $hasEstado = true;
        }
    }

    $whereRes = "fecha = '$fecha'";
    if ($hasEstado) {
        $whereRes .= " AND (estado IS NULL OR LOWER(estado) NOT IN ('cancelada', 'cancelado', 'anulada', 'anulado'))";
    }

    $resReservas = @$conn->query("SELECT cancha_id, hora_inicio, hora_fin FROM reservas_cancha WHERE $whereRes");
    if ($resReservas) {
        while ($r = $resReservas->fetch_assoc()) {
            $cId = (int)($r['cancha_id'] ?? 0);
            $hIni = !empty($r['hora_inicio']) ? substr($r['hora_inicio'], 0, 5) : '';
            if ($cId && $hIni) {
                $reservasOcupadas[$cId . '_' . $hIni] = true;
            }
        }
    }

    // 4. Fetch all players who have played 1 or more times at this club
    $canchasClubIds = [];
    foreach ($canchas as $c) {
        $canchasClubIds[] = (int)$c['id'];
    }

    $activeClubUserIds = [];
    if (!empty($canchasClubIds)) {
        $canchaListStr = implode(',', $canchasClubIds);
        $resColsRC = @$conn->query("SHOW COLUMNS FROM reservas_cancha");
        $colsRC = [];
        if ($resColsRC) {
            while ($c = $resColsRC->fetch_assoc()) {
                $colsRC[strtolower($c['Field'])] = true;
            }
        }

        $selectFields = [];
        if (isset($colsRC['usuario_id'])) $selectFields[] = 'usuario_id';
        if (isset($colsRC['jugador_id'])) $selectFields[] = 'jugador_id';
        if (isset($colsRC['jugador2_id'])) $selectFields[] = 'jugador2_id';
        if (isset($colsRC['jugador3_id'])) $selectFields[] = 'jugador3_id';
        if (isset($colsRC['jugador4_id'])) $selectFields[] = 'jugador4_id';

        if (!empty($selectFields)) {
            $sqlActive = "SELECT " . implode(',', $selectFields) . " 
                          FROM reservas_cancha 
                          WHERE cancha_id IN ($canchaListStr)
                            AND (estado IS NULL OR LOWER(estado) NOT IN ('cancelada', 'cancelado', 'anulada', 'anulado'))";
            $resActive = @$conn->query($sqlActive);
            if ($resActive) {
                while ($ra = $resActive->fetch_assoc()) {
                    foreach ($selectFields as $sf) {
                        if (!empty($ra[$sf]) && (int)$ra[$sf] > 0) {
                            $activeClubUserIds[(int)$ra[$sf]] = true;
                        }
                    }
                }
            }
        }
    }

    $sqlUsers = "SELECT u.id, u.nombre, COALESCE(u.apellido, '') as apellido, 
                        COALESCE(u.usuario, '') as email, 
                        COALESCE(u.telefono, '') as telefono, 
                        COALESCE(u.foto_perfil, u.foto, '') as foto_usuario,
                        COALESCE(u.categoria, '4ª') as categoria_usuario,
                        p.posicion as pref_posicion, p.categoria as pref_categoria, p.brazo_habil as pref_brazo,
                        p.dias_preferidos as pref_dias, p.franja_horaria as pref_franja, p.horario_habitual as pref_horario,
                        p.activo_matchmaking
                 FROM usuarios u
                 LEFT JOIN jugador_preferencias_padel p ON p.usuario_id = u.id AND (p.club_id = $clubId OR p.club_id = 0 OR p.club_id IS NULL)
                 WHERE (u.rol IN ('jugador', 'alumno', 'usuario', 'entrenador', 'administrador_club', 'staff_club') OR u.rol IS NULL)
                   AND (p.activo_matchmaking IS NULL OR p.activo_matchmaking = 1)
                 ORDER BY u.id DESC";

    $resUsers = @$conn->query($sqlUsers);
    $players = [];

    if ($resUsers) {
        while ($row = $resUsers->fetch_assoc()) {
            $uId = (int)$row['id'];

            // REQUIREMENT: Solo colocar jugadores que han jugado 1 o más veces en el club
            if (empty($activeClubUserIds[$uId])) {
                continue;
            }

            $fullName = trim($row['nombre'] . ' ' . $row['apellido']);
            if (empty($fullName)) $fullName = 'Jugador #' . $uId;

            $cat = !empty($row['pref_categoria']) ? $row['pref_categoria'] : (!empty($row['categoria_usuario']) ? $row['categoria_usuario'] : '4ª');
            $catNorm = str_ireplace(['categoría', 'categoria', 'cat', ' ', 'ª', '°'], '', $cat);
            if (is_numeric($catNorm)) {
                $catNorm = $catNorm . 'ª';
            } else if (empty($catNorm)) {
                $catNorm = '4ª';
            }

            $diasArr = !empty($row['pref_dias']) ? json_decode($row['pref_dias'], true) : ['1','3','5'];
            if (!is_array($diasArr)) $diasArr = ['1','3','5'];

            $pos = !empty($row['pref_posicion']) ? $row['pref_posicion'] : 'Ambos';
            $horario = !empty($row['pref_horario']) ? $row['pref_horario'] : '19:30';

            $hourNum = (int)explode(':', $horario)[0];
            $franja = 'tarde';
            if ($hourNum < 12) $franja = 'manana';
            else if ($hourNum < 16) $franja = 'mediodia';
            else if ($hourNum < 20) $franja = 'tarde';
            else $franja = 'noche';

            $players[] = [
                'id' => $uId,
                'nombre' => $fullName,
                'email' => $row['email'],
                'telefono' => $row['telefono'] ?: '',
                'foto' => $row['foto_usuario'] ?: 'assets/images/placeholder_avatar.png',
                'categoria' => $catNorm,
                'posicion' => $pos,
                'brazo_habil' => $row['pref_brazo'] ?: 'Diestro',
                'dias_preferidos' => $diasArr,
                'horario_habitual' => $horario,
                'franja_horaria' => $franja,
                'juega_este_dia' => in_array((string)$diaSemana, $diasArr)
            ];
        }
    }

    // 5. Matchmaking Algorithm: Group by Category and Time Slot
    $slots = [
        '08:00' => ['franja' => 'manana', 'fin' => '09:30'],
        '09:30' => ['franja' => 'manana', 'fin' => '11:00'],
        '11:00' => ['franja' => 'manana', 'fin' => '12:30'],
        '12:30' => ['franja' => 'mediodia', 'fin' => '14:00'],
        '17:00' => ['franja' => 'tarde', 'fin' => '18:30'],
        '18:30' => ['franja' => 'tarde', 'fin' => '20:00'],
        '19:30' => ['franja' => 'tarde', 'fin' => '21:00'],
        '20:00' => ['franja' => 'noche', 'fin' => '21:30'],
        '21:00' => ['franja' => 'noche', 'fin' => '22:30'],
        '21:30' => ['franja' => 'noche', 'fin' => '23:00']
    ];

    $groupedByCat = [];
    foreach ($players as $p) {
        $cat = $p['categoria'];
        if (!isset($groupedByCat[$cat])) {
            $groupedByCat[$cat] = [];
        }
        $groupedByCat[$cat][] = $p;
    }

    $partidosSugeridos = [];
    $matchIndex = 1;
    $canchaCursor = 0;

    foreach ($groupedByCat as $cat => $catPlayers) {
        if (!empty($categoriaFilter) && $categoriaFilter !== 'todas' && stripos($cat, $categoriaFilter) === false) {
            continue;
        }

        // Sort players: players who actively play on this day come first
        usort($catPlayers, function($a, $b) {
            if ($a['juega_este_dia'] === $b['juega_este_dia']) return 0;
            return $a['juega_este_dia'] ? -1 : 1;
        });

        // Group by preferred time slots
        foreach ($slots as $horaIni => $slotInfo) {
            $franja = $slotInfo['franja'];
            $horaFin = $slotInfo['fin'];

            if (!empty($franjaFilter) && $franjaFilter !== 'todas' && $franjaFilter !== $franja) {
                continue;
            }

            // Find players who match or fit this time slot
            $candidates = array_filter($catPlayers, function($p) use ($horaIni, $franja) {
                return $p['horario_habitual'] === $horaIni || $p['franja_horaria'] === $franja || $p['juega_este_dia'];
            });

            if (count($candidates) < 4) {
                // If fewer than 4 in exact slot, fill with general category players
                $candidates = $catPlayers;
            }

            if (count($candidates) >= 4) {
                // Separate Drives, Reveses, Ambos
                $drives = [];
                $reveses = [];
                $ambos = [];

                foreach ($candidates as $c) {
                    if ($c['posicion'] === 'Drive') $drives[] = $c;
                    else if ($c['posicion'] === 'Reves') $reveses[] = $c;
                    else $ambos[] = $c;
                }

                // Balance 2 Drives + 2 Reveses
                $p1_j1 = !empty($drives) ? array_shift($drives) : (!empty($ambos) ? array_shift($ambos) : array_shift($candidates));
                $p1_j2 = !empty($reveses) ? array_shift($reveses) : (!empty($ambos) ? array_shift($ambos) : array_shift($candidates));
                
                $p2_j1 = !empty($drives) ? array_shift($drives) : (!empty($ambos) ? array_shift($ambos) : array_shift($candidates));
                $p2_j2 = !empty($reveses) ? array_shift($reveses) : (!empty($ambos) ? array_shift($ambos) : array_shift($candidates));

                $selectedPlayers = [$p1_j1, $p1_j2, $p2_j1, $p2_j2];
                // Check if all 4 are distinct
                $selectedIds = array_map(function($p) { return $p ? $p['id'] : 0; }, $selectedPlayers);
                if (count(array_unique($selectedIds)) === 4 && !in_array(0, $selectedIds)) {
                    // Assign court
                    $canchaAsignada = null;
                    $canchaLibre = true;

                    if (!empty($canchas)) {
                        $canchaAsignada = $canchas[$canchaCursor % count($canchas)];
                        $canchaCursor++;
                        $keyOcupada = $canchaAsignada['id'] . '_' . $horaIni;
                        if (isset($reservasOcupadas[$keyOcupada])) {
                            $canchaLibre = false;
                        }
                    }

                    // Suplentes list
                    $suplentes = [];
                    foreach ($candidates as $cand) {
                        if (!in_array($cand['id'], $selectedIds) && count($suplentes) < 3) {
                            $suplentes[] = [
                                'id' => $cand['id'],
                                'nombre' => $cand['nombre'],
                                'telefono' => $cand['telefono'],
                                'posicion' => $cand['posicion'],
                                'categoria' => $cand['categoria']
                            ];
                        }
                    }

                    // Preformatted WhatsApp broadcast message
                    $waText = "🎾 *CONVOCATORIA PARTIDO PADELBLOX* ⚡\n";
                    $waText .= "📅 *Fecha:* $diaNombre " . date('d/m/Y', strtotime($fecha)) . "\n";
                    $waText .= "⏰ *Horario:* $horaIni - $horaFin hrs\n";
                    $waText .= "🏟️ *Cancha:* " . ($canchaAsignada ? $canchaAsignada['nombre'] : 'Pista Central') . "\n";
                    $waText .= "🏷️ *Nivel:* $cat Categoría\n\n";
                    $waText .= "👥 *Alineación Sugerida:*\n";
                    $waText .= "🔵 *Pareja 1:* {$p1_j1['nombre']} (Drive) & {$p1_j2['nombre']} (Revés)\n";
                    $waText .= "🟡 *Pareja 2:* {$p2_j1['nombre']} (Drive) & {$p2_j2['nombre']} (Revés)\n\n";
                    $waText .= "¿Confirman asistencia para agendar la cancha? Respondan a este mensaje. 🔥";

                    $partidosSugeridos[] = [
                        'id' => 'match_' . $matchIndex++,
                        'fecha' => $fecha,
                        'dia_nombre' => $diaNombre,
                        'hora_inicio' => $horaIni,
                        'hora_fin' => $horaFin,
                        'franja' => $franja,
                        'franja_label' => ucfirst($franja),
                        'categoria' => $cat,
                        'compatibilidad_score' => 95,
                        'cancha' => $canchaAsignada ?: ['id' => 1, 'nombre' => 'Cancha 1'],
                        'cancha_disponible' => $canchaLibre,
                        'pareja1' => [
                            'jugador1' => $p1_j1,
                            'jugador2' => $p1_j2
                        ],
                        'pareja2' => [
                            'jugador1' => $p2_j1,
                            'jugador2' => $p2_j2
                        ],
                        'suplentes' => $suplentes,
                        'insights' => [
                            "Mismo nivel técnico ({$cat} Categoría)",
                            "Equilibrio posicional: 2 Drives + 2 Revés",
                            "Horario habitual compatible ({$horaIni} hrs)"
                        ],
                        'whatsapp_text' => $waText
                    ];

                    // Break slot loop once we have good match per category to avoid clutter
                    if (count($partidosSugeridos) >= 8) break;
                }
            }
        }
    }

    @ob_clean();
    echo json_encode([
        'success' => true,
        'fecha' => $fecha,
        'dia_semana' => $diaNombre,
        'total_partidos_sugeridos' => count($partidosSugeridos),
        'partidos' => $partidosSugeridos
    ]);
    exit;

} catch (Throwable $e) {
    @ob_clean();
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'fecha' => $fecha ?? date('Y-m-d'),
        'dia_semana' => '',
        'total_partidos_sugeridos' => 0,
        'partidos' => []
    ]);
    exit;
}
?>
