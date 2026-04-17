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
        // 1. INTENTO CON TABLA MODERNA (torneo_partidos_cuadro)
        $sql = "SELECT p.*, 
                p1.nombre_pareja as p1_n, p1.nombre_jugador_1 as p1_j1, p1.nombre_jugador_2 as p1_j2,
                p2.nombre_pareja as p2_n, p2.nombre_jugador_1 as p2_j1, p2.nombre_jugador_2 as p2_j2
                FROM torneo_partidos_cuadro p 
                LEFT JOIN torneo_participantes p1 ON p.pareja1_id = p1.id
                LEFT JOIN torneo_participantes p2 ON p.pareja2_id = p2.id
                WHERE p.categoria_id = ?
                ORDER BY p.nivel DESC, p.posicion ASC";

        $stmt = @$conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $categoria_id);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $row['pareja1_nombre'] = $row['p1_n'] ?: ($row['p1_j1'] ? $row['p1_j1'] . ($row['p1_j2'] ? ' / ' . $row['p1_j2'] : '') : 'TBD');
                    $row['pareja2_nombre'] = $row['p2_n'] ?: ($row['p2_j1'] ? $row['p2_j1'] . ($row['p2_j2'] ? ' / ' . $row['p2_j2'] : '') : 'TBD');
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
