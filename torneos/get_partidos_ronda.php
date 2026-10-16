<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

// Manejo de preflight OPTIONS - FUNDAMENTAL para que no dé 500 en preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Silenciar errores fatales de MySQLi (opcional, pero útil para depurar sin 500)
mysqli_report(MYSQLI_REPORT_OFF);

try {
    require_once "../db.php";
    
    // Si la conexión fallara
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    $categoria_id = (int)($_GET['categoria_id'] ?? 0);
    $partidos = [];

    if ($categoria_id > 0) {
        $nombresNivel = [
            1 => 'Final',
            2 => 'Semifinal',
            3 => 'Cuartos',
            4 => 'Octavos',
            5 => 'Dieciseisavos'
        ];

        // 1. INTENTO CON TABLA MODERNA (torneo_partidos_cuadro)
        $sql = "SELECT p.*, 
                COALESCE(p1.nombre_pareja, part1.nombre_pareja) as p1_n, 
                COALESCE(u1_1.nombre, p1.jugador1_nombre_manual, part1.nombre_jugador_1) as p1_j1,
                COALESCE(u1_2.nombre, p1.jugador2_nombre_manual, part1.nombre_jugador_2) as p1_j2,
                i1.es_semilla as p1_es_semilla, i1.nro_siembra as p1_nro_siembra,
                COALESCE(p2.nombre_pareja, part2.nombre_pareja) as p2_n, 
                COALESCE(u2_1.nombre, p2.jugador1_nombre_manual, part2.nombre_jugador_1) as p2_j1,
                COALESCE(u2_2.nombre, p2.jugador2_nombre_manual, part2.nombre_jugador_2) as p2_j2,
                i2.es_semilla as p2_es_semilla, i2.nro_siembra as p2_nro_siembra,
                c.nombre as cancha_nombre
                FROM torneo_partidos_cuadro p 
                LEFT JOIN torneo_parejas p1 ON p.pareja1_id = p1.id
                LEFT JOIN usuarios u1_1 ON p1.jugador1_id = u1_1.id
                LEFT JOIN usuarios u1_2 ON p1.jugador2_id = u1_2.id
                LEFT JOIN torneo_participantes part1 ON p.pareja1_id = part1.id
                LEFT JOIN torneo_inscripciones i1 ON (i1.pareja_id = p.pareja1_id AND i1.categoria_id = p.categoria_id)
                LEFT JOIN torneo_parejas p2 ON p.pareja2_id = p2.id
                LEFT JOIN usuarios u2_1 ON p2.jugador1_id = u2_1.id
                LEFT JOIN usuarios u2_2 ON p2.jugador2_id = u2_2.id
                LEFT JOIN torneo_participantes part2 ON p.pareja2_id = part2.id
                LEFT JOIN torneo_inscripciones i2 ON (i2.pareja_id = p.pareja2_id AND i2.categoria_id = p.categoria_id)
                LEFT JOIN canchas c ON p.cancha_id = c.id
                WHERE p.categoria_id = ?
                ORDER BY p.nivel DESC, p.posicion ASC";

        $stmt = @$conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $categoria_id);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $row['ronda'] = $nombresNivel[(int)$row['nivel']] ?? 'Ronda ' . $row['nivel'];
                    $name1 = $row['p1_n'] ?: ($row['p1_j1'] ? $row['p1_j1'] . ($row['p1_j2'] ? ' / ' . $row['p1_j2'] : '') : null);
                    $name2 = $row['p2_n'] ?: ($row['p2_j1'] ? $row['p2_j1'] . ($row['p2_j2'] ? ' / ' . $row['p2_j2'] : '') : null);
                    
                    if ($row['p1_es_semilla'] && $row['p1_nro_siembra']) {
                        $name1 = "[CS " . $row['p1_nro_siembra'] . "] " . ($name1 ?: 'TBD');
                    }
                    if ($row['p2_es_semilla'] && $row['p2_nro_siembra']) {
                        $name2 = "[CS " . $row['p2_nro_siembra'] . "] " . ($name2 ?: 'TBD');
                    }

                    $row['pareja1_nombre'] = $name1 ?: 'Clasif. TBD';
                    $row['pareja2_nombre'] = $name2 ?: 'Clasif. TBD';
                    $row['court'] = $row['cancha_nombre'] ?? ($row['cancha_id'] ? 'Cancha ' . $row['cancha_id'] : 'Cancha TBD');
                    $row['tempTime'] = $row['fecha_inicio'] ? date('H:i', strtotime($row['fecha_inicio'])) : null;
                    $row['tempDate'] = $row['fecha_inicio'] ? date('Y-m-d', strtotime($row['fecha_inicio'])) : null;
                    $partidos[] = $row;
                }
            }
            $stmt->close();
        }

        // 2. FALLBACK A TABLA V2 (si la anterior falló o está vacía)
        if (count($partidos) === 0) {
            $sqlOld = "SELECT p.*, 
                    p1.nombre_pareja as pareja1_nombre, p2.nombre_pareja as pareja2_nombre
                    FROM torneo_partidos_v2 p 
                    LEFT JOIN torneo_participantes p1 ON p.pareja1_id = p1.id
                    LEFT JOIN torneo_participantes p2 ON p.pareja2_id = p2.id
                    WHERE p.categoria_id = ? AND p.grupo_id IS NULL";
            
            $stOld = @$conn->prepare($sqlOld);
            if ($stOld) {
                $stOld->bind_param("i", $categoria_id);
                if ($stOld->execute()) {
                    $resOld = $stOld->get_result();
                    while ($row = $resOld->fetch_assoc()) {
                        $partidos[] = $row;
                    }
                }
                $stOld->close();
            }
        }
    }

    echo json_encode($partidos);

} catch (Exception $e) {
    // Si algo falló, devolvemos un JSON de error pero con HTTP 200 para no romper el frontend
    // y permitir que el simulador tome el control.
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
?>
