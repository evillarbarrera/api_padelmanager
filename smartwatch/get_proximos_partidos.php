<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type, Accept");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

mysqli_report(MYSQLI_REPORT_OFF);
require_once "../db.php";
require_once "../auth/auth_helper.php";

$userId = validateToken();
if (!$userId && !empty($_GET['usuario_id'])) {
    $userId = (int)$_GET['usuario_id'];
}

if (!$userId) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Usuario no autenticado"]);
    exit;
}

try {
    $proximos_partidos = [];
    $hoy = date('Y-m-d');

    // 1. OBTENER RESERVAS DE CANCHA ACTIVAS / PRÓXIMAS
    $sqlReservas = "SELECT r.*, 
                           c.nombre as cancha_nombre, 
                           c.categoria as cancha_categoria,
                           c.tipo as cancha_tipo,
                           cl.id as club_id,
                           cl.nombre as club_nombre,
                           u1.nombre as j1_nombre, u1.apellido as j1_ape,
                           u2.nombre as j2_nombre, u2.apellido as j2_ape,
                           u3.nombre as j3_nombre, u3.apellido as j3_ape,
                           u4.nombre as j4_nombre, u4.apellido as j4_ape
                    FROM reservas_cancha r
                    JOIN canchas c ON r.cancha_id = c.id
                    JOIN clubes cl ON c.club_id = cl.id
                    LEFT JOIN usuarios u1 ON r.usuario_id = u1.id
                    LEFT JOIN usuarios u2 ON r.jugador2_id = u2.id
                    LEFT JOIN usuarios u3 ON r.jugador3_id = u3.id
                    LEFT JOIN usuarios u4 ON r.jugador4_id = u4.id
                    WHERE (r.usuario_id = ? OR r.jugador2_id = ? OR r.jugador3_id = ? OR r.jugador4_id = ?)
                      AND (r.fecha >= DATE_SUB(CURDATE(), INTERVAL 1 DAY))
                    ORDER BY r.fecha ASC, r.hora_inicio ASC
                    LIMIT 10";

    $stmtR = $conn->prepare($sqlReservas);
    if ($stmtR) {
        $stmtR->bind_param("iiii", $userId, $userId, $userId, $userId);
        $stmtR->execute();
        $resR = $stmtR->get_result();

        while ($r = $resR->fetch_assoc()) {
            $p1 = trim(($r['j1_nombre'] ?? 'Jugador 1') . ' ' . ($r['j1_ape'] ?? ''));
            if (!empty($r['j2_nombre'])) {
                $p1 .= ' / ' . trim($r['j2_nombre'] . ' ' . ($r['j2_ape'] ?? ''));
            } else {
                $p1 .= ' / Pareja';
            }

            $p2 = !empty($r['j3_nombre']) ? trim($r['j3_nombre'] . ' ' . ($r['j3_ape'] ?? '')) : 'Rival 1';
            if (!empty($r['j4_nombre'])) {
                $p2 .= ' / ' . trim($r['j4_nombre'] . ' ' . ($r['j4_ape'] ?? ''));
            } else {
                $p2 .= ' / Rival 2';
            }

            $hIni = !empty($r['hora_inicio']) ? substr($r['hora_inicio'], 0, 5) : '19:00';
            $hFin = !empty($r['hora_fin']) ? substr($r['hora_fin'], 0, 5) : '20:30';

            $proximos_partidos[] = [
                "id" => "res_" . $r['id'],
                "reserva_id" => (int)$r['id'],
                "liga_partido_id" => null,
                "tipo_origen" => "reserva",
                "tipo_label" => "Cancha Reservada",
                "tipo_actividad" => "partido",
                "club_id" => (int)$r['club_id'],
                "club_nombre" => $r['club_nombre'],
                "cancha_nombre" => $r['cancha_nombre'],
                "cancha_tipo" => $r['cancha_tipo'] ?? 'Pádel Cristal',
                "fecha" => $r['fecha'],
                "fecha_formateada" => date('d/m/Y', strtotime($r['fecha'])),
                "hora_inicio" => $hIni,
                "hora_fin" => $hFin,
                "horario_texto" => "$hIni - $hFin hrs",
                "pareja1" => $p1,
                "pareja2" => $p2,
                "punto_oro" => true,
                "sets" => 3,
                "es_hoy" => ($r['fecha'] === $hoy)
            ];
        }
    }

    // 2. OBTENER ENTRENAMIENTOS INDIVIDUALES Y CLASES RESERVADAS
    $checkEntrena = $conn->query("SHOW TABLES LIKE 'reservas'");
    if ($checkEntrena && $checkEntrena->num_rows > 0) {
        $sqlEntrena = "SELECT r.id as reserva_id, r.fecha, r.hora_inicio, r.hora_fin, r.estado,
                              COALESCE(r.tipo, p.tipo, 'individual') as tipo,
                              p.nombre as pack_nombre,
                              u_e.nombre as entrenador_nombre, u_e.apellido as entrenador_ape,
                              c.id as club_id, c.nombre as club_nombre
                       FROM reservas r
                       INNER JOIN reserva_jugadores rj ON rj.reserva_id = r.id
                       LEFT JOIN packs p ON p.id = r.pack_id
                       LEFT JOIN clubes c ON c.id = r.club_id
                       LEFT JOIN usuarios u_e ON u_e.id = r.entrenador_id
                       WHERE rj.jugador_id = ?
                         AND r.estado = 'reservado'
                         AND (r.fecha >= DATE_SUB(CURDATE(), INTERVAL 1 DAY))
                       ORDER BY r.fecha ASC, r.hora_inicio ASC
                       LIMIT 10";
        $stmtE = $conn->prepare($sqlEntrena);
        if ($stmtE) {
            $stmtE->bind_param("i", $userId);
            $stmtE->execute();
            $resE = $stmtE->get_result();
            while ($ent = $resE->fetch_assoc()) {
                $hIni = !empty($ent['hora_inicio']) ? substr($ent['hora_inicio'], 0, 5) : '18:00';
                $hFin = !empty($ent['hora_fin']) ? substr($ent['hora_fin'], 0, 5) : '19:00';
                $coachName = trim(($ent['entrenador_nombre'] ?? 'Coach') . ' ' . ($ent['entrenador_ape'] ?? ''));
                $tipoLabel = ($ent['tipo'] === 'grupal') ? 'Clase Grupal' : 'Entrenamiento';

                $proximos_partidos[] = [
                    "id" => "entrena_" . $ent['reserva_id'],
                    "reserva_id" => (int)$ent['reserva_id'],
                    "liga_partido_id" => null,
                    "tipo_origen" => "entrenamiento",
                    "tipo_label" => $tipoLabel,
                    "tipo_actividad" => "entrenamiento",
                    "entrenador_nombre" => $coachName,
                    "club_id" => !empty($ent['club_id']) ? (int)$ent['club_id'] : 1,
                    "club_nombre" => !empty($ent['club_nombre']) ? $ent['club_nombre'] : 'Club PadelBlox',
                    "cancha_nombre" => !empty($ent['pack_nombre']) ? $ent['pack_nombre'] : 'Entrenamiento Pádel',
                    "cancha_tipo" => 'Pista Técnica',
                    "fecha" => $ent['fecha'],
                    "fecha_formateada" => date('d/m/Y', strtotime($ent['fecha'])),
                    "hora_inicio" => $hIni,
                    "hora_fin" => $hFin,
                    "horario_texto" => "$hIni - $hFin hrs",
                    "pareja1" => "Coach: $coachName",
                    "pareja2" => "Sesión Técnica",
                    "punto_oro" => false,
                    "sets" => 1,
                    "es_hoy" => ($ent['fecha'] === $hoy)
                ];
            }
        }
    }

    // 3. OBTENER PARTIDOS DE LIGA PROGRAMADOS
    $checkLiga = $conn->query("SHOW TABLES LIKE 'liga_partidos'");
    if ($checkLiga && $checkLiga->num_rows > 0) {
        $sqlLigas = "SELECT p.*, 
                            par1.nombre as pareja1_nombre,
                            par2.nombre as pareja2_nombre,
                            l.nombre as liga_nombre,
                            l.club_id as club_id,
                            cl.nombre as club_nombre,
                            can.nombre as cancha_nombre
                     FROM liga_partidos p
                     JOIN liga_parejas par1 ON p.pareja1_id = par1.id
                     JOIN liga_parejas par2 ON p.pareja2_id = par2.id
                     LEFT JOIN liga_categorias c ON p.categoria_id = c.id
                     LEFT JOIN ligas l ON c.liga_id = l.id
                     LEFT JOIN clubes cl ON l.club_id = cl.id
                     LEFT JOIN canchas can ON p.cancha_id = can.id
                     WHERE (par1.jugador1_id = ? OR par1.jugador2_id = ? OR par2.jugador1_id = ? OR par2.jugador2_id = ?)
                       AND (p.estado IS NULL OR p.estado = 'Pendiente' OR p.estado = 'Programado')
                       AND (p.fecha IS NULL OR p.fecha >= DATE_SUB(CURDATE(), INTERVAL 1 DAY))
                     ORDER BY p.fecha ASC, p.hora_inicio ASC
                     LIMIT 5";

        $stmtL = $conn->prepare($sqlLigas);
        if ($stmtL) {
            $stmtL->bind_param("iiii", $userId, $userId, $userId, $userId);
            $stmtL->execute();
            $resL = $stmtL->get_result();

            while ($lp = $resL->fetch_assoc()) {
                $hIni = !empty($lp['hora_inicio']) ? substr($lp['hora_inicio'], 0, 5) : '20:00';
                $fec = !empty($lp['fecha']) ? $lp['fecha'] : $hoy;

                $proximos_partidos[] = [
                    "id" => "liga_" . $lp['id'],
                    "reserva_id" => null,
                    "liga_partido_id" => (int)$lp['id'],
                    "tipo_origen" => "liga",
                    "tipo_label" => "Liga PadelBlox",
                    "tipo_actividad" => "partido",
                    "club_id" => !empty($lp['club_id']) ? (int)$lp['club_id'] : 1,
                    "club_nombre" => !empty($lp['club_nombre']) ? $lp['club_nombre'] : 'Liga PadelBlox',
                    "cancha_nombre" => !empty($lp['cancha_nombre']) ? $lp['cancha_nombre'] : 'Cancha de Liga',
                    "cancha_tipo" => 'Pádel Oficial',
                    "fecha" => $fec,
                    "fecha_formateada" => date('d/m/Y', strtotime($fec)),
                    "hora_inicio" => $hIni,
                    "hora_fin" => date('H:i', strtotime("$hIni +90 minutes")),
                    "horario_texto" => "$hIni hrs",
                    "pareja1" => $lp['pareja1_nombre'] ?? 'Mi Pareja',
                    "pareja2" => $lp['pareja2_nombre'] ?? 'Pareja Rival',
                    "punto_oro" => true,
                    "sets" => 3,
                    "es_hoy" => ($fec === $hoy)
                ];
            }
        }
    }

    // Ordenar todas las actividades por fecha y hora
    usort($proximos_partidos, function ($a, $b) {
        $cmpDate = strcmp($a['fecha'], $b['fecha']);
        if ($cmpDate !== 0) return $cmpDate;
        return strcmp($a['hora_inicio'], $b['hora_inicio']);
    });

    // 4. OBTENER INFORMACIÓN DEL JUGADOR
    $uRes = $conn->query("SELECT id, nombre, apellido, foto_perfil, nivel_juego FROM usuarios WHERE id = $userId LIMIT 1");
    $usuario = $uRes ? $uRes->fetch_assoc() : null;

    echo json_encode([
        "success" => true,
        "usuario" => $usuario,
        "total_partidos" => count($proximos_partidos),
        "partido_destacado" => !empty($proximos_partidos[0]) ? $proximos_partidos[0] : null,
        "proximos_partidos" => $proximos_partidos
    ]);

} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
