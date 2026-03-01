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

$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('n');

$numDays = cal_days_in_month(CAL_GREGORIAN, $month, $year);

$labels = [];
$dataUsuarios = array_fill(0, $numDays, 0);
$dataPacks = array_fill(0, $numDays, 0);
$dataIngresos = array_fill(0, $numDays, 0);

for($i = 1; $i <= $numDays; $i++){
    $labels[] = str_pad($i, 2, '0', STR_PAD_LEFT) . '/' . str_pad($month, 2, '0', STR_PAD_LEFT);
}

// Usuarios por día
try {
    $sqlU = "SELECT DAY(created_at) as d, COUNT(*) as c FROM usuarios WHERE YEAR(created_at) = ? AND MONTH(created_at) = ? GROUP BY d";
    $stmtU = $conn->prepare($sqlU);
    $stmtU->bind_param("ii", $year, $month);
    $stmtU->execute();
    $resU = $stmtU->get_result();
    while ($row = $resU->fetch_assoc()) {
        $idx = (int)$row['d'] - 1;
        if(isset($dataUsuarios[$idx])) $dataUsuarios[$idx] = (int)$row['c'];
    }
} catch (Exception $e) {}

// Packs ingresos y ventas por día
try {
    $sqlP = "SELECT DAY(pj.fecha_inicio) as d, COUNT(pj.id) as tp, SUM(p.precio) as i 
             FROM pack_jugadores pj 
             JOIN packs p ON pj.pack_id = p.id 
             WHERE YEAR(pj.fecha_inicio) = ? AND MONTH(pj.fecha_inicio) = ? GROUP BY d";
    $stmtP = $conn->prepare($sqlP);
    $stmtP->bind_param("ii", $year, $month);
    $stmtP->execute();
    $resP = $stmtP->get_result();
    while ($row = $resP->fetch_assoc()) {
        $idx = (int)$row['d'] - 1;
        if(isset($dataPacks[$idx])) {
            $dataPacks[$idx] = (int)$row['tp'];
            $dataIngresos[$idx] = (float)$row['i'];
        }
    }
} catch (Exception $e) {}

echo json_encode([
    "success"=>true, 
    "labels"=>$labels, 
    "datasets"=>[
        "usuarios"=>$dataUsuarios,
        "packs"=>$dataPacks,
        "ingresos"=>$dataIngresos
    ]
]);
?>
