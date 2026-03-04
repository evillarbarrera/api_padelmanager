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

$month = isset($_GET['month']) ? (int)$_GET['month'] : 0;
$year = isset($_GET['year']) ? (int)$_GET['year'] : 0;

$stats = [
    'total_usuarios' => 0,
    'total_entrenadores' => 0,
    'total_jugadores' => 0,
    'total_clubes' => 0,
    'total_packs_vendidos' => 0,
    'ingresos_packs' => 0,
    'ganancia_estimada' => 0,
    'total_torneos' => 0,
    'packs_individuales' => 0,
    'packs_multijugador' => 0,
    'packs_grupales' => 0,
    'nuevos_usuarios' => 0
];

// Usuarios (Totales)
try {
    $sql_users = "SELECT rol, COUNT(*) as count FROM usuarios GROUP BY rol";
    $res_users = $conn->query($sql_users);
    if ($res_users) {
        while ($row = $res_users->fetch_assoc()) {
            $stats['total_usuarios'] += $row['count'];
            $rol = strtolower($row['rol']);
            if ($rol === 'entrenador' || $rol === 'entrenador_padel') {
                $stats['total_entrenadores'] += $row['count'];
            } elseif ($rol === 'jugador') {
                $stats['total_jugadores'] += $row['count'];
            }
        }
    }
} catch (Exception $e) {}

// Nuevos Usuarios (Mes filtrado)
try {
    $whereUsers = "";
    if ($month > 0 && $year > 0) {
        $whereUsers = " WHERE MONTH(created_at) = $month AND YEAR(created_at) = $year";
        $sql_new = "SELECT COUNT(*) as count FROM usuarios $whereUsers";
        $res_new = $conn->query($sql_new);
        if ($res_new && $row = $res_new->fetch_assoc()) {
            $stats['nuevos_usuarios'] = (int)$row['count'];
        }
    }
} catch (Exception $e) {}

// Clubes (No se filtran por fecha)
try {
    $sql_clubes = "SELECT COUNT(*) as count FROM clubes";
    $res_clubes = $conn->query($sql_clubes);
    if ($res_clubes && $row = $res_clubes->fetch_assoc()) {
        $stats['total_clubes'] = (int)$row['count'];
    }
} catch (Exception $e) {}

// Packs vendidos y ganancias (Filtrado por mes/año si aplica)
try {
    $wherePacks = " WHERE 1=1 ";
    if ($month > 0 && $year > 0) {
        $wherePacks .= " AND MONTH(pj.fecha_inicio) = $month AND YEAR(pj.fecha_inicio) = $year";
    }

    $sql_packs = "SELECT p.tipo, p.cantidad_personas, COUNT(pj.id) as total_packs, SUM(p.precio) as total_ingresos 
                  FROM pack_jugadores pj 
                  JOIN packs p ON pj.pack_id = p.id
                  $wherePacks
                  GROUP BY p.tipo, p.cantidad_personas";
                  
    $res_packs = $conn->query($sql_packs);
    if ($res_packs) {
        while ($row = $res_packs->fetch_assoc()) {
            $count = (int)$row['total_packs'];
            $ingresos = (float)$row['total_ingresos'];
            
            $stats['total_packs_vendidos'] += $count;
            $stats['ingresos_packs'] += $ingresos;
            
            if ($row['tipo'] === 'grupal') {
                $stats['packs_grupales'] += $count;
            } else if ($row['tipo'] === 'individual') {
                if ($row['cantidad_personas'] > 1) {
                    $stats['packs_multijugador'] += $count;
                } else {
                    $stats['packs_individuales'] += $count;
                }
            }
        }
        $stats['ganancia_estimada'] = $stats['ingresos_packs'] * 0.035;
    }
} catch (Exception $e) {}

// Torneos
try {
    $whereTorneos = " WHERE 1=1 ";
    if ($month > 0 && $year > 0) {
        $whereTorneos .= " AND MONTH(fecha) = $month AND YEAR(fecha) = $year";
    }
    $sql_torneos = "SELECT COUNT(*) as count FROM torneos $whereTorneos";
    $res_torneos = $conn->query($sql_torneos);
    if ($res_torneos && $row = $res_torneos->fetch_assoc()) {
        $stats['total_torneos'] = (int)$row['count'];
    }
} catch (Exception $e) {}

echo json_encode(["success" => true, "data" => $stats]);
?>
