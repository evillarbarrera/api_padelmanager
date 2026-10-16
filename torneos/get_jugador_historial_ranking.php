<?php
// api/torneos/get_jugador_historial_ranking.php
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

$usuario_id = isset($_GET['usuario_id']) ? (int)$_GET['usuario_id'] : 0;
if (!$usuario_id) {
    http_response_code(400);
    echo json_encode(["error" => "Parámetro usuario_id es requerido"]);
    exit;
}

try {
    // 1. Obtener datos del jugador
    $sqlUser = "SELECT u.id, u.nombre, COALESCE(u.apellido, '') as apellido, u.usuario, 
                       COALESCE(u.foto_perfil, u.foto, '') as foto, 
                       COALESCE(u.puntos_ranking, 0) as puntos_ranking,
                       COALESCE(u.rol, 'jugador') as role
                FROM usuarios u 
                WHERE u.id = ?";
    $stmtU = $conn->prepare($sqlUser);
    $stmtU->bind_param("i", $usuario_id);
    $stmtU->execute();
    $user = $stmtU->get_result()->fetch_assoc();

    if (!$user) {
        http_response_code(404);
        echo json_encode(["error" => "Jugador no encontrado"]);
        exit;
    }

    $nombreCompleto = trim(($user['nombre'] ?? 'Jugador') . ' ' . ($user['apellido'] ?? ''));

    // Puntos por categoría en ranking_categorias
    $puntosPorCategoria = [];
    $stmtCats = $conn->prepare("SELECT categoria, puntos FROM ranking_categorias WHERE usuario_id = ?");
    if ($stmtCats) {
        $stmtCats->bind_param("i", $usuario_id);
        $stmtCats->execute();
        $resCats = $stmtCats->get_result();
        while ($rc = $resCats->fetch_assoc()) {
            $puntosPorCategoria[$rc['categoria']] = (int)$rc['puntos'];
        }
    }

    // 1.1 Asegurar columnas de puntos_anulados en torneo_participantes si no existen
    $check_a1 = @$conn->query("SHOW COLUMNS FROM torneo_participantes LIKE 'puntos_anulados_1'");
    if ($check_a1 && $check_a1->num_rows == 0) {
        @$conn->query("ALTER TABLE torneo_participantes ADD `puntos_anulados_1` TINYINT(1) DEFAULT 0");
    }
    $check_a2 = @$conn->query("SHOW COLUMNS FROM torneo_participantes LIKE 'puntos_anulados_2'");
    if ($check_a2 && $check_a2->num_rows == 0) {
        @$conn->query("ALTER TABLE torneo_participantes ADD `puntos_anulados_2` TINYINT(1) DEFAULT 0");
    }

    // 2. Obtener inscripciones a torneos americanos
    $sqlTorneos = "SELECT tp.id as inscripcion_id, tp.torneo_id, tp.jugador_id, tp.jugador2_id,
                          tp.nombre_pareja, tp.nombre_externo_1, tp.nombre_externo_2,
                          COALESCE(tp.puntos_anulados_1, 0) as puntos_anulados_1,
                          COALESCE(tp.puntos_anulados_2, 0) as puntos_anulados_2,
                          t.nombre as torneo_nombre, t.fecha, t.categoria, t.estado,
                          t.puntos_1er_lugar, t.puntos_2do_lugar, t.puntos_3er_lugar, t.puntos_4to_lugar,
                          t.puntos_participacion, t.tipo_torneo, t.modalidad,
                          c.nombre as club_nombre,
                          u2.nombre as companero_nombre, u2.apellido as companero_apellido
                   FROM torneo_participantes tp
                   JOIN torneos_americanos t ON tp.torneo_id = t.id
                   LEFT JOIN clubes c ON t.club_id = c.id
                   LEFT JOIN usuarios u2 ON (
                       CASE 
                           WHEN tp.jugador_id = ? THEN tp.jugador2_id 
                           ELSE tp.jugador_id 
                       END = u2.id
                   )
                   WHERE tp.jugador_id = ? OR tp.jugador2_id = ?
                   ORDER BY t.fecha DESC, t.id DESC";

    $stmtT = $conn->prepare($sqlTorneos);
    $stmtT->bind_param("iii", $usuario_id, $usuario_id, $usuario_id);
    $stmtT->execute();
    $resT = $stmtT->get_result();

    $historial = [];
    $totalOro1 = 0;
    $totalOro2 = 0;
    $totalPlata1 = 0;
    $totalPlata2 = 0;
    $partidosTotalesGanados = 0;
    $partidosTotalesJugados = 0;
    $totalPuntosCalculados = 0;

    while ($t = $resT->fetch_assoc()) {
        $torneo_id = (int)$t['torneo_id'];
        $cat = $t['categoria'] ?: 'Cuarta';
        $p_part = (int)($t['puntos_participacion'] ?? 5);
        $p_oro_1 = (int)($t['puntos_1er_lugar'] ?? 100);
        $p_oro_2 = (int)($t['puntos_2do_lugar'] ?? 60);
        $p_plata_1 = (int)($t['puntos_3er_lugar'] ?? 40);
        $p_plata_2 = (int)($t['puntos_4to_lugar'] ?? 20);

        // Verificar si los puntos de este torneo están anulados para este jugador
        $isJug1 = ($t['jugador_id'] == $usuario_id);
        $estaAnulado = $isJug1 ? ((int)($t['puntos_anulados_1'] ?? 0) === 1) : ((int)($t['puntos_anulados_2'] ?? 0) === 1);

        // Pareja / Compañero
        $companero = '';
        if ($t['jugador_id'] == $usuario_id) {
            $companero = trim(($t['companero_nombre'] ?? '') . ' ' . ($t['companero_apellido'] ?? ''));
            if (empty($companero)) $companero = $t['nombre_externo_2'] ?? '';
        } else {
            $companero = trim(($t['companero_nombre'] ?? '') . ' ' . ($t['companero_apellido'] ?? ''));
            if (empty($companero)) $companero = $t['nombre_externo_1'] ?? '';
        }
        if (empty($companero)) $companero = $t['nombre_pareja'] ?: 'Sin pareja';

        // Partidos del usuario en este torneo
        $sqlPartidos = "SELECT * FROM torneo_partidos 
                        WHERE torneo_id = ? 
                          AND (jugador1_id = ? OR jugador2_id = ? OR jugador3_id = ? OR jugador4_id = ?)
                        ORDER BY id ASC";
        $stmtP = $conn->prepare($sqlPartidos);
        $stmtP->bind_param("iiiii", $torneo_id, $usuario_id, $usuario_id, $usuario_id, $usuario_id);
        $stmtP->execute();
        $resP = $stmtP->get_result();

        $partidosJugados = 0;
        $partidosGanados = 0;
        $partidosPerdidos = 0;
        $gamesFavor = 0;
        $gamesContra = 0;
        $posicion = 'Fase de Grupos';
        $badgeClass = 'badge-participacion';
        $puntosPosicion = 0;
        $iconoPosicion = '🎾';

        while ($m = $resP->fetch_assoc()) {
            $isPair1 = ($m['jugador1_id'] == $usuario_id || $m['jugador2_id'] == $usuario_id);
            $ptFavor = $isPair1 ? (int)$m['puntos_t1'] : (int)$m['puntos_t2'];
            $ptContra = $isPair1 ? (int)$m['puntos_t2'] : (int)$m['puntos_t1'];

            if ($m['finalizado']) {
                $partidosJugados++;
                $gamesFavor += $ptFavor;
                $gamesContra += $ptContra;
                $gano = ($ptFavor > $ptContra);

                if ($gano) $partidosGanados++;
                else $partidosPerdidos++;

                // Análisis de Fase Final
                if ($m['fase'] === 'Finales') {
                    if ($m['grupo_id'] === 'Oro') {
                        if ($gano) {
                            $posicion = '1° Lugar Oro (Campeón)';
                            $badgeClass = 'badge-gold';
                            $iconoPosicion = '🏆';
                            $puntosPosicion = $p_oro_1;
                            $totalOro1++;
                        } else {
                            $posicion = '2° Lugar Oro (Subcampeón)';
                            $badgeClass = 'badge-silver';
                            $iconoPosicion = '🥈';
                            $puntosPosicion = $p_oro_2;
                            $totalOro2++;
                        }
                    } elseif ($m['grupo_id'] === 'Plata') {
                        if ($gano) {
                            $posicion = '1° Lugar Plata (Campeón Plata)';
                            $badgeClass = 'badge-bronze';
                            $iconoPosicion = '🥉';
                            $puntosPosicion = $p_plata_1;
                            $totalPlata1++;
                        } else {
                            $posicion = '2° Lugar Plata (Subcampeón Plata)';
                            $badgeClass = 'badge-copper';
                            $iconoPosicion = '🎖️';
                            $puntosPosicion = $p_plata_2;
                            $totalPlata2++;
                        }
                    }
                } elseif ($m['fase'] === 'Semifinales' && $posicion === 'Fase de Grupos') {
                    $posicion = 'Semifinalista ' . ($m['grupo_id'] ?: 'Playoff');
                    $badgeClass = 'badge-semis';
                    $iconoPosicion = '⚡';
                }
            }
        }

        $puntosRealesCalculados = $puntosPosicion + $p_part;

        if ($estaAnulado) {
            $puntosParticipacion = 0;
            $puntosPosicion = 0;
            $totalPuntosTorneo = 0;
            $badgeClass = 'badge-anulado';
            $iconoPosicion = '🚫';
            $posicion = $posicion . ' (Puntos Anulados)';
        } else {
            $puntosParticipacion = $p_part;
            $totalPuntosTorneo = $puntosPosicion + $puntosParticipacion;
            $totalPuntosCalculados += $totalPuntosTorneo;
        }

        $partidosTotalesGanados += $partidosGanados;
        $partidosTotalesJugados += $partidosJugados;

        $historial[] = [
            "torneo_id" => $torneo_id,
            "torneo_nombre" => $t['torneo_nombre'] ?: "Torneo Americano #$torneo_id",
            "fecha" => $t['fecha'],
            "club_nombre" => $t['club_nombre'] ?: 'Club Oficial',
            "categoria" => $cat,
            "companero" => $companero,
            "posicion" => $posicion,
            "badge_class" => $badgeClass,
            "icono_posicion" => $iconoPosicion,
            "puntos_posicion" => $puntosPosicion,
            "puntos_participacion" => $puntosParticipacion,
            "puntos_totales" => $totalPuntosTorneo,
            "puntos_reales" => $puntosRealesCalculados,
            "anulado" => $estaAnulado,
            "partidos_jugados" => $partidosJugados,
            "partidos_ganados" => $partidosGanados,
            "partidos_perdidos" => $partidosPerdidos,
            "games_favor" => $gamesFavor,
            "games_contra" => $gamesContra,
            "dif_games" => ($gamesFavor - $gamesContra)
        ];
    }

    $winRate = ($partidosTotalesJugados > 0) ? round(($partidosTotalesGanados / $partidosTotalesJugados) * 100) : 0;

    echo json_encode([
        "success" => true,
        "jugador" => [
            "id" => (int)$user['id'],
            "nombre" => $nombreCompleto,
            "usuario" => $user['usuario'] ?? '',
            "foto" => $user['foto'] ?? '',
            "puntos_ranking" => (int)$user['puntos_ranking'],
            "puntos_por_categoria" => $puntosPorCategoria
        ],
        "resumen" => [
            "torneos_jugados" => count($historial),
            "campeonatos_oro" => $totalOro1,
            "subcampeonatos_oro" => $totalOro2,
            "campeonatos_plata" => $totalPlata1,
            "subcampeonatos_plata" => $totalPlata2,
            "total_podios" => ($totalOro1 + $totalOro2 + $totalPlata1 + $totalPlata2),
            "partidos_jugados" => $partidosTotalesJugados,
            "partidos_ganados" => $partidosTotalesGanados,
            "win_rate" => $winRate,
            "total_puntos_calculados" => $totalPuntosCalculados
        ],
        "historial" => $historial
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "error" => $e->getMessage()]);
}
?>
