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

    // 1. Ensure preferences table exists
    $sqlTable = "CREATE TABLE IF NOT EXISTS jugador_preferencias_padel (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        club_id INT DEFAULT NULL,
        posicion ENUM('Drive', 'Reves', 'Ambos') DEFAULT 'Ambos',
        categoria VARCHAR(50) DEFAULT '4ª',
        brazo_habil ENUM('Diestro', 'Zurdo') DEFAULT 'Diestro',
        dias_preferidos VARCHAR(255) DEFAULT '[\"1\",\"3\",\"5\"]',
        franja_horaria VARCHAR(50) DEFAULT 'tarde',
        horario_habitual VARCHAR(50) DEFAULT '19:30',
        activo_matchmaking TINYINT(1) DEFAULT 1,
        notas TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_club (usuario_id, club_id),
        INDEX idx_user (usuario_id),
        INDEX idx_club (club_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    @$conn->query($sqlTable);

    // SILENT SCHEMA FIX for usuarios
    function ensureColumnUsersSafe($conn, $column, $definition) {
        $check = @$conn->query("SHOW COLUMNS FROM usuarios LIKE '$column'");
        if ($check && $check->num_rows == 0) {
            @$conn->query("ALTER TABLE usuarios ADD `$column` $definition");
        }
    }
    ensureColumnUsersSafe($conn, 'apellido', "VARCHAR(100) NULL");
    ensureColumnUsersSafe($conn, 'telefono', "VARCHAR(50) NULL");
    ensureColumnUsersSafe($conn, 'categoria', "VARCHAR(50) DEFAULT '4ª'");
    ensureColumnUsersSafe($conn, 'foto', "VARCHAR(255) NULL");
    ensureColumnUsersSafe($conn, 'foto_perfil', "VARCHAR(255) NULL");

    $clubId = isset($_GET['club_id']) ? (int)$_GET['club_id'] : 0;
    if (!$clubId) {
        $resClub = @$conn->query("SELECT id FROM clubes LIMIT 1");
        if ($resClub && $row = $resClub->fetch_assoc()) {
            $clubId = (int)$row['id'];
        }
    }

    // Get court IDs for this club
    $canchasClubRes = @$conn->query("SELECT id FROM canchas WHERE club_id = $clubId");
    $canchasClubIds = [];
    if ($canchasClubRes) {
        while ($cr = $canchasClubRes->fetch_assoc()) {
            $canchasClubIds[] = (int)$cr['id'];
        }
    }

    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $categoriaFilter = isset($_GET['categoria']) ? trim($_GET['categoria']) : '';
    $posicionFilter = isset($_GET['posicion']) ? trim($_GET['posicion']) : '';
    $franjaFilter = isset($_GET['franja']) ? trim($_GET['franja']) : '';

    // 2. Fetch all users who are players or match participants
    $whereUser = "u.rol IN ('jugador', 'alumno', 'usuario', 'entrenador', 'administrador_club', 'staff_club') OR u.rol IS NULL";
    if (!empty($search)) {
        $s = $conn->real_escape_string($search);
        $whereUser .= " AND (u.nombre LIKE '%$s%' OR u.apellido LIKE '%$s%' OR u.usuario LIKE '%$s%' OR u.telefono LIKE '%$s%')";
    }

    $sqlUsers = "SELECT u.id, u.nombre, COALESCE(u.apellido, '') as apellido, 
                        COALESCE(u.usuario, '') as email, 
                        COALESCE(u.telefono, '') as telefono, 
                        COALESCE(u.foto_perfil, u.foto, '') as foto_usuario,
                        COALESCE(u.categoria, '4ª') as categoria_usuario, 
                        COALESCE(u.rol, 'jugador') as rol,
                        p.posicion as pref_posicion, p.categoria as pref_categoria, p.brazo_habil as pref_brazo,
                        p.dias_preferidos as pref_dias, p.franja_horaria as pref_franja, p.horario_habitual as pref_horario,
                        p.activo_matchmaking, p.notas as pref_notas
                 FROM usuarios u
                 LEFT JOIN (
                     SELECT * FROM jugador_preferencias_padel 
                     ORDER BY id DESC
                 ) p ON p.usuario_id = u.id AND (p.club_id = $clubId OR p.club_id = 0 OR p.club_id IS NULL)
                 WHERE $whereUser
                 GROUP BY u.id
                 ORDER BY u.id DESC";

    $resUsers = $conn->query($sqlUsers);
    $usersList = [];
    $userIds = [];

    if ($resUsers) {
        while ($row = $resUsers->fetch_assoc()) {
            $uId = (int)$row['id'];
            $userIds[] = $uId;
            
            $fullName = trim($row['nombre'] . ' ' . $row['apellido']);
            if (empty($fullName)) $fullName = 'Jugador #' . $uId;

            $cat = !empty($row['pref_categoria']) ? $row['pref_categoria'] : (!empty($row['categoria_usuario']) ? $row['categoria_usuario'] : '4ª');
            $catNorm = str_ireplace(['categoría', 'categoria', 'cat', ' ', 'ª', '°'], '', $cat);
            if (is_numeric($catNorm)) {
                $catNorm = $catNorm . 'ª';
            } else if (empty($catNorm)) {
                $catNorm = '4ª';
            }

            $usersList[$uId] = [
                'id' => $uId,
                'nombre' => $fullName,
                'email' => $row['email'],
                'telefono' => $row['telefono'] ?: '',
                'foto' => $row['foto_usuario'] ?: 'assets/images/placeholder_avatar.png',
                'categoria' => $catNorm,
                'posicion' => $row['pref_posicion'] ?: 'Ambos',
                'brazo_habil' => $row['pref_brazo'] ?: 'Diestro',
                'pref_dias' => !empty($row['pref_dias']) ? $row['pref_dias'] : null,
                'pref_horario' => !empty($row['pref_horario']) ? $row['pref_horario'] : null,
                'pref_franja' => !empty($row['pref_franja']) ? $row['pref_franja'] : null,
                'activo_matchmaking' => isset($row['activo_matchmaking']) ? (int)$row['activo_matchmaking'] : 1,
                'notas' => $row['pref_notas'] ?: '',
                // Computed from match history
                'total_partidos' => 0,
                'historial_dias' => [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0],
                'historial_horas' => [],
                'historial_dia_horas' => [0 => [], 1 => [], 2 => [], 3 => [], 4 => [], 5 => [], 6 => []],
                'ultimo_partido' => null,
                'club_frecuente' => 'PadelBlox Club',
                'confiabilidad' => 95
            ];
        }
    }

    // 3. Inspect columns in reservas_cancha to avoid unknown column errors
    if (!empty($userIds)) {
        $idsStr = implode(',', $userIds);

        $resCols = @$conn->query("SHOW COLUMNS FROM reservas_cancha");
        $colsRC = [];
        if ($resCols) {
            while ($c = $resCols->fetch_assoc()) {
                $colsRC[strtolower($c['Field'])] = true;
            }
        }

        $fields = ['r.id', 'r.fecha', 'r.hora_inicio', 'r.hora_fin', 'r.cancha_id'];
        $whereMatchParts = [];

        if (isset($colsRC['usuario_id'])) {
            $fields[] = 'r.usuario_id';
            $whereMatchParts[] = "r.usuario_id IN ($idsStr)";
        }
        if (isset($colsRC['jugador_id'])) {
            $fields[] = 'r.jugador_id';
            $whereMatchParts[] = "r.jugador_id IN ($idsStr)";
        }
        if (isset($colsRC['jugador2_id'])) {
            $fields[] = 'r.jugador2_id';
            $whereMatchParts[] = "r.jugador2_id IN ($idsStr)";
        }
        if (isset($colsRC['jugador3_id'])) {
            $fields[] = 'r.jugador3_id';
            $whereMatchParts[] = "r.jugador3_id IN ($idsStr)";
        }
        if (isset($colsRC['jugador4_id'])) {
            $fields[] = 'r.jugador4_id';
            $whereMatchParts[] = "r.jugador4_id IN ($idsStr)";
        }

        if (!empty($whereMatchParts)) {
            $whereMatch = implode(' OR ', $whereMatchParts);
            $estadoCheck = isset($colsRC['estado']) ? "AND (r.estado IS NULL OR LOWER(r.estado) NOT IN ('cancelada', 'cancelado', 'anulada', 'anulado'))" : "";

            // Filter reservations specifically for this club's courts
            $canchaFilter = "";
            if (!empty($canchasClubIds)) {
                $canchaFilter = "AND r.cancha_id IN (" . implode(',', $canchasClubIds) . ")";
            } else if ($clubId > 0) {
                $canchaFilter = "AND r.cancha_id IN (SELECT id FROM canchas WHERE club_id = $clubId)";
            }

            $sqlMatches = "SELECT " . implode(', ', $fields) . ", DAYOFWEEK(r.fecha) - 1 as dia_semana
                           FROM reservas_cancha r
                           WHERE ($whereMatch) $estadoCheck $canchaFilter
                           ORDER BY r.fecha DESC, r.hora_inicio DESC";

            $resMatches = @$conn->query($sqlMatches);
            if ($resMatches) {
                while ($m = $resMatches->fetch_assoc()) {
                    $diaSemana = (int)($m['dia_semana'] ?? 0);
                    $horaIni = !empty($m['hora_inicio']) ? substr($m['hora_inicio'], 0, 5) : '19:30';
                    $fecha = $m['fecha'] ?? '';

                    $participants = [];
                    if (isset($m['usuario_id']) && (int)$m['usuario_id'] > 0) $participants[] = (int)$m['usuario_id'];
                    if (isset($m['jugador_id']) && (int)$m['jugador_id'] > 0) $participants[] = (int)$m['jugador_id'];
                    if (isset($m['jugador2_id']) && (int)$m['jugador2_id'] > 0) $participants[] = (int)$m['jugador2_id'];
                    if (isset($m['jugador3_id']) && (int)$m['jugador3_id'] > 0) $participants[] = (int)$m['jugador3_id'];
                    if (isset($m['jugador4_id']) && (int)$m['jugador4_id'] > 0) $participants[] = (int)$m['jugador4_id'];

                    foreach ($participants as $pId) {
                        if (isset($usersList[$pId])) {
                            $usersList[$pId]['total_partidos']++;
                            $usersList[$pId]['historial_dias'][$diaSemana] = ($usersList[$pId]['historial_dias'][$diaSemana] ?? 0) + 1;
                            $usersList[$pId]['historial_horas'][$horaIni] = ($usersList[$pId]['historial_horas'][$horaIni] ?? 0) + 1;
                            if (!isset($usersList[$pId]['historial_dia_horas'][$diaSemana])) {
                                $usersList[$pId]['historial_dia_horas'][$diaSemana] = [];
                            }
                            $usersList[$pId]['historial_dia_horas'][$diaSemana][$horaIni] = ($usersList[$pId]['historial_dia_horas'][$diaSemana][$horaIni] ?? 0) + 1;
                            if (!$usersList[$pId]['ultimo_partido'] && !empty($fecha)) {
                                $usersList[$pId]['ultimo_partido'] = $fecha . ' ' . $horaIni;
                            }
                        }
                    }
                }
            }
        }
    }

    // 4. Format preferences and detected habits for each player
    $diasNombres = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    $resultData = [];

    foreach ($usersList as $uId => $u) {
        $hasCustomPref = !empty($u['pref_dias']) || !empty($u['pref_horario']);
        $topDays = [];

        if (!empty($u['pref_dias'])) {
            $prefDiasArr = is_array($u['pref_dias']) ? $u['pref_dias'] : json_decode($u['pref_dias'], true);
            if (is_array($prefDiasArr)) {
                $topDays = array_map('strval', $prefDiasArr);
            }
        }

        if (empty($topDays)) {
            // Deduce from match history only if no explicit preferences were saved
            arsort($u['historial_dias']);
            foreach ($u['historial_dias'] as $dIdx => $count) {
                if ($count > 0 && count($topDays) < 4) {
                    $topDays[] = (string)$dIdx;
                }
            }
        }

        if (empty($topDays)) {
            $topDays = ['1', '3', '5'];
        }

        // Horario habitual: priority to explicitly saved pref_horario
        $horarioHabitual = !empty($u['pref_horario']) ? $u['pref_horario'] : '';
        if (empty($horarioHabitual)) {
            arsort($u['historial_horas']);
            $topHours = array_keys(array_slice($u['historial_horas'], 0, 3));
            $horarioHabitual = !empty($topHours) ? $topHours[0] : '19:30';
        }

        $hourNum = (int)explode(':', $horarioHabitual)[0];
        $franja = 'tarde';
        if ($hourNum < 12) $franja = 'manana';
        else if ($hourNum < 16) $franja = 'mediodia';
        else if ($hourNum < 20) $franja = 'tarde';
        else $franja = 'noche';

        $diasLabels = array_map(function($d) use ($diasNombres) {
            $idx = (int)$d;
            return $diasNombres[$idx] ?? 'Lun';
        }, $topDays);

        // Day x Hour Cross Mapping:
        // ONLY the days in $topDays (the player's preferred days) should be populated!
        $horariosPorDia = [];
        $diasKeysList = ['1', '2', '3', '4', '5', '6', '0']; // Lun to Dom
        foreach ($diasKeysList as $dK) {
            $dNum = (int)$dK;
            if (in_array($dK, $topDays)) {
                if (!$hasCustomPref && !empty($u['historial_dia_horas'][$dNum])) {
                    arsort($u['historial_dia_horas'][$dNum]);
                    $topH = array_key_first($u['historial_dia_horas'][$dNum]);
                    $horariosPorDia[$dK] = $topH ?: $horarioHabitual;
                } else {
                    $horariosPorDia[$dK] = $horarioHabitual;
                }
            } else {
                $horariosPorDia[$dK] = null;
            }
        }

        $formattedUser = [
            'id' => $u['id'],
            'nombre' => $u['nombre'],
            'email' => $u['email'],
            'telefono' => $u['telefono'],
            'foto' => $u['foto'],
            'categoria' => $u['categoria'],
            'posicion' => $u['posicion'],
            'brazo_habil' => $u['brazo_habil'],
            'dias_preferidos' => $topDays,
            'dias_labels' => $diasLabels,
            'horario_habitual' => $horarioHabitual,
            'horarios_por_dia' => $horariosPorDia,
            'franja_horaria' => $franja,
            'total_partidos' => $u['total_partidos'],
            'ultimo_partido' => $u['ultimo_partido'] ?: 'Sin partidos registrados',
            'confiabilidad' => min(100, 80 + ($u['total_partidos'] * 2)),
            'activo_matchmaking' => $u['activo_matchmaking'],
            'notas' => $u['notas']
        ];

        // REQUIREMENT: Solo colocar jugadores que han jugado 1 o más veces en el club
        if ((int)$u['total_partidos'] < 1) {
            continue;
        }

        // Filter checks
        if (!empty($categoriaFilter) && $categoriaFilter !== 'todas' && stripos($formattedUser['categoria'], $categoriaFilter) === false) {
            continue;
        }
        if (!empty($posicionFilter) && $posicionFilter !== 'todas' && strcasecmp($formattedUser['posicion'], $posicionFilter) !== 0 && $formattedUser['posicion'] !== 'Ambos') {
            continue;
        }
        if (!empty($franjaFilter) && $franjaFilter !== 'todas' && strcasecmp($formattedUser['franja_horaria'], $franjaFilter) !== 0) {
            continue;
        }

        $resultData[] = $formattedUser;
    }

    // Summary Statistics
    $totalJugadores = count($resultData);
    $catCounts = [];
    $franjaCounts = ['manana' => 0, 'mediodia' => 0, 'tarde' => 0, 'noche' => 0];
    $posCounts = ['Drive' => 0, 'Reves' => 0, 'Ambos' => 0];

    foreach ($resultData as $r) {
        $catCounts[$r['categoria']] = ($catCounts[$r['categoria']] ?? 0) + 1;
        $franjaCounts[$r['franja_horaria']] = ($franjaCounts[$r['franja_horaria']] ?? 0) + 1;
        $posCounts[$r['posicion']] = ($posCounts[$r['posicion']] ?? 0) + 1;
    }

    @ob_clean();
    echo json_encode([
        'success' => true,
        'total' => $totalJugadores,
        'stats' => [
            'total_jugadores' => $totalJugadores,
            'distribucion_categorias' => $catCounts,
            'distribucion_franjas' => $franjaCounts,
            'distribucion_posiciones' => $posCounts,
            'horario_pico' => '19:30 - 21:00',
            'dia_mas_demandado' => 'Miércoles y Jueves'
        ],
        'jugadores' => $resultData
    ]);
    exit;

} catch (Throwable $e) {
    @ob_clean();
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'jugadores' => [],
        'stats' => [
            'total_jugadores' => 0,
            'distribucion_categorias' => [],
            'distribucion_franjas' => [],
            'distribucion_posiciones' => []
        ]
    ]);
    exit;
}
?>
