<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
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
    'nuevos_usuarios' => 0,
    'online_now' => 0
];

// Usuarios Online (Últimos 10 minutos)
try {
    $sql_online = "SELECT COUNT(DISTINCT usuario_id) as online FROM web_analytics WHERE created_at >= NOW() - INTERVAL 10 MINUTE";
    $res_online = $conn->query($sql_online);
    if ($res_online && $row = $res_online->fetch_assoc()) {
        $stats['online_now'] = (int)$row['online'];
    }
    // Si no hay usuarios registrados, contar visitas totales (anónimas)
    if ($stats['online_now'] === 0) {
        $sql_online_anon = "SELECT COUNT(*) as online FROM web_analytics WHERE created_at >= NOW() - INTERVAL 10 MINUTE";
        $res_online_anon = $conn->query($sql_online_anon);
        if ($res_online_anon && $row = $res_online_anon->fetch_assoc()) {
            $stats['online_now'] = (int)$row['online'];
        }
    }
} catch (Exception $e) {}

// Usuarios (Totales)
try {
    $sql_users = "SELECT rol, COUNT(*) as count FROM usuarios GROUP BY rol";
    $res_users = $conn->query($sql_users);
    if ($res_users) {
        while ($row = $res_users->fetch_assoc()) {
            $stats['total_usuarios'] += $row['count'];
            $rol = strtolower($row['rol']);
            if (strpos($rol, 'entrenador') !== false) {
                $stats['total_entrenadores'] += $row['count'];
            } elseif ($rol === 'jugador') {
                $stats['total_jugadores'] += $row['count'];
            }
        }
    }
} catch (Exception $e) {}

// Crecimiento Mensual (Este mes vs Anterior)
try {
    $currentM = date('m'); $currentY = date('Y');
    $prevDate = date('Y-m-d', strtotime("first day of last month"));
    $prevM = date('m', strtotime($prevDate)); $prevY = date('Y', strtotime($prevDate));

    $sql_curr = "SELECT 
                    COUNT(CASE WHEN rol LIKE '%entrenador%' THEN 1 END) as e_curr,
                    COUNT(CASE WHEN rol = 'jugador' THEN 1 END) as j_curr
                 FROM usuarios WHERE MONTH(created_at) = $currentM AND YEAR(created_at) = $currentY";
    $res_curr = $conn->query($sql_curr);
    $data_curr = $res_curr->fetch_assoc();

    $sql_prev = "SELECT 
                    COUNT(CASE WHEN rol LIKE '%entrenador%' THEN 1 END) as e_prev,
                    COUNT(CASE WHEN rol = 'jugador' THEN 1 END) as j_prev
                 FROM usuarios WHERE MONTH(created_at) = $prevM AND YEAR(created_at) = $prevY";
    $res_prev = $conn->query($sql_prev);
    $data_prev = $res_prev->fetch_assoc();

    $stats['crecimiento'] = [
        'entrenadores' => [
            'actual' => (int)$data_curr['e_curr'],
            'anterior' => (int)$data_prev['e_prev'],
            'porcentaje' => $data_prev['e_prev'] > 0 ? (($data_curr['e_curr'] - $data_prev['e_prev']) / $data_prev['e_prev']) * 100 : 100
        ],
        'jugadores' => [
            'actual' => (int)$data_curr['j_curr'],
            'anterior' => (int)$data_prev['j_prev'],
            'porcentaje' => $data_prev['j_prev'] > 0 ? (($data_curr['j_curr'] - $data_prev['j_prev']) / $data_prev['j_prev']) * 100 : 100
        ]
    ];
} catch (Exception $e) {}

// Mock Social (Integración futura)
$stats['social'] = [
    'instagram' => ['seguidores' => 1420, 'engagement' => '3.8%', 'posts_mes' => 8],
    'tiktok' => ['seguidores' => 950, 'likes' => '1.2k', 'compartidos' => 45]
];

// Top Páginas (Analytics Interno)
try {
    $sql_ana = "SELECT pagina, COUNT(*) as visitas FROM web_analytics GROUP BY pagina ORDER BY visitas DESC LIMIT 5";
    $res_ana = $conn->query($sql_ana);
    $stats['top_paginas'] = [];
    if ($res_ana && $res_ana->num_rows > 0) {
        while($row = $res_ana->fetch_assoc()) { $stats['top_paginas'][] = $row; }
    } else {
        $stats['top_paginas'] = [['pagina' => '/entrenador-home', 'visitas' => 0]];
    }
} catch (Exception $e) {}

// Analítica por Dispositivo (Real)
try {
    $sql_dev = "SELECT dispositivo, COUNT(*) as count FROM web_analytics GROUP BY dispositivo";
    $res_dev = $conn->query($sql_dev);
    $stats['dispositivos'] = ['Mobile' => 0, 'PC' => 0];
    while($row = $res_dev->fetch_assoc()) {
        $stats['dispositivos'][$row['dispositivo']] = (int)$row['count'];
    }
} catch (Exception $e) {}

// Analítica por Región y Comuna (Real)
try {
    $sql_reg = "SELECT region, COUNT(*) as count FROM direcciones GROUP BY region ORDER BY count DESC LIMIT 5";
    $res_reg = $conn->query($sql_reg);
    $stats['regiones'] = [];
    while($row = $res_reg->fetch_assoc()) { $stats['regiones'][] = $row; }

    $sql_com = "SELECT comuna, COUNT(*) as count FROM direcciones GROUP BY comuna ORDER BY count DESC LIMIT 5";
    $res_com = $conn->query($sql_com);
    $stats['comunas'] = [];
    while($row = $res_com->fetch_assoc()) { $stats['comunas'][] = $row; }
} catch (Exception $e) {}

// Género (Aún no existe en DB, Mock con proyección)
$stats['genero'] = [
    ['label' => 'Hombres', 'count' => (int)($stats['total_jugadores'] * 0.65)],
    ['label' => 'Mujeres', 'count' => (int)($stats['total_jugadores'] * 0.35)]
];

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
