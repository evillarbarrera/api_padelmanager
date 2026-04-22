<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // No ensuciar el JSON

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Configuración manual (Espejo de db.php)
$host = "localhost";
$user = "c2632100_manager";
$pass = "boBUraze40";
$dbname = "c2632100_manager";

try {
    $conn = new mysqli($host, $user, $pass, $dbname);
    if ($conn->connect_error) {
        die(json_encode(["success" => false, "error" => "DB_CONN_FAIL"]));
    }
    $conn->set_charset("utf8mb4");

    $jugador_id = isset($_GET['jugador_id']) ? intval($_GET['jugador_id']) : 0;
    $entrenador_id = isset($_GET['entrenador_id']) ? intval($_GET['entrenador_id']) : 0;

    if (!$jugador_id || !$entrenador_id) {
        die(json_encode(["success" => true, "pendientes" => 0, "disponibles" => 1]));
    }

    $compradas = 0;
    $usadas = 0;
    $futuras = 0;

    // CONSULTA 1: Compradas
    $q1 = "SELECT SUM(pj.sesiones_totales) as totales FROM pack_jugadores pj JOIN packs p ON p.id = pj.pack_id WHERE pj.jugador_id = $jugador_id AND p.entrenador_id = $entrenador_id AND p.tipo NOT IN ('grupal', 'pack_grupal')";
    if ($res1 = $conn->query($q1)) {
        $row1 = $res1->fetch_assoc();
        $compradas = (int)($row1['totales'] ?? 0);
    }

    // CONSULTA 2: Usadas
    $q2 = "SELECT COUNT(DISTINCT rj.reserva_id) as usadas FROM reserva_jugadores rj JOIN reservas r ON r.id = rj.reserva_id WHERE rj.jugador_id = $jugador_id AND r.entrenador_id = $entrenador_id AND r.estado != 'cancelado' AND r.tipo NOT IN ('grupal', 'pack_grupal')";
    if ($res2 = $conn->query($q2)) {
        $row2 = $res2->fetch_assoc();
        $usadas = (int)($row2['usadas'] ?? 0);
    }

    // CONSULTA 3: Futuras
    $q3 = "SELECT COUNT(DISTINCT rj.reserva_id) as futuras FROM reserva_jugadores rj JOIN reservas r ON r.id = rj.reserva_id WHERE rj.jugador_id = $jugador_id AND r.entrenador_id = $entrenador_id AND r.estado != 'cancelado' AND r.tipo NOT IN ('grupal', 'pack_grupal') AND (r.fecha > CURDATE() OR (r.fecha = CURDATE() AND r.hora_inicio > CURTIME()))";
    if ($res3 = $conn->query($q3)) {
        $row3 = $res3->fetch_assoc();
        $futuras = (int)($row3['futuras'] ?? 0);
    }

    echo json_encode([
        "success" => true,
        "pendientes" => $futuras,
        "disponibles" => max(0, $compradas - $usadas),
        "compradas" => $compradas,
        "mode" => "bulletproof_v2"
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false, 
        "error" => "SQL_ERROR", 
        "msg" => $e->getMessage(),
        "pendientes" => 0,
        "disponibles" => 1
    ]);
} finally {
    if (isset($conn) && $conn) $conn->close();
}
