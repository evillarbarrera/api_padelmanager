<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$stats = [
    'total_usuarios' => 0,
    'total_entrenadores' => 0,
    'total_jugadores' => 0,
    'total_clubes' => 0,
    'total_packs_vendidos' => 0,
    'ingresos_packs' => 0,
    'total_torneos' => 0
];

// Usuarios
try {
    $sql_users = "SELECT rol, COUNT(*) as count FROM usuarios GROUP BY rol";
    $res_users = $conn->query($sql_users);
    if ($res_users) {
        while ($row = $res_users->fetch_assoc()) {
            $stats['total_usuarios'] += $row['count'];
            if (strtolower($row['rol']) === 'entrenador') {
                $stats['total_entrenadores'] += $row['count'];
            } elseif (strtolower($row['rol']) === 'jugador') {
                $stats['total_jugadores'] += $row['count'];
            }
        }
    }
} catch (Exception $e) {}

// Clubes
try {
    $sql_clubes = "SELECT COUNT(*) as count FROM clubes";
    $res_clubes = $conn->query($sql_clubes);
    if ($res_clubes && $row = $res_clubes->fetch_assoc()) {
        $stats['total_clubes'] = (int)$row['count'];
    }
} catch (Exception $e) {}

// Packs vendidos y ganancias
try {
    $sql_packs = "SELECT COUNT(pj.id) as total_packs, SUM(p.precio) as total_ingresos 
                  FROM pack_jugadores pj 
                  JOIN packs p ON pj.pack_id = p.id";
    $res_packs = $conn->query($sql_packs);
    if ($res_packs && $row = $res_packs->fetch_assoc()) {
        $stats['total_packs_vendidos'] = (int)$row['total_packs'];
        $stats['ingresos_packs'] = (float)$row['total_ingresos'];
        $stats['ganancia_estimada'] = $stats['ingresos_packs'] * 0.035;
    }
} catch (Exception $e) {}

// Torneos
try {
    $sql_torneos = "SELECT COUNT(*) as count FROM torneos";
    $res_torneos = $conn->query($sql_torneos);
    if ($res_torneos && $row = $res_torneos->fetch_assoc()) {
        $stats['total_torneos'] = (int)$row['count'];
    }
} catch (Exception $e) {}

echo json_encode(["success" => true, "data" => $stats]);
?>
