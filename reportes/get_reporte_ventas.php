<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

$club_id = $_GET['club_id'] ?? null;

if (!$club_id) {
    http_response_code(400);
    echo json_encode(["error" => "club_id es requerido"]);
    exit;
}

try {
    /** @var PDO $pdo */
    
    // Verificar si existe la tabla inventario_ventas
    $hasInventario = false;
    try {
        $check = $pdo->query("SELECT 1 FROM inventario_ventas LIMIT 1");
        $hasInventario = true;
    } catch(PDOException $e) {
        $hasInventario = false;
    }

    // Construir la consulta de manera dinámica
    $baseSql = "";
    if ($hasInventario) {
        $baseSql .= "SELECT DATE(fecha) as fecha_v, total as monto FROM inventario_ventas WHERE club_id = $club_id UNION ALL ";
    }
    
    $baseSql .= "SELECT DATE(r.fecha) as fecha_v, r.precio as monto
                 FROM reservas_cancha r
                 JOIN canchas c ON r.cancha_id = c.id
                 WHERE c.club_id = $club_id AND r.pagado = 1 AND r.estado != 'Cancelada'";

    // 1. Daily sales (last 30 days)
    $stmtDay = $pdo->query("SELECT fecha_v as label, COALESCE(SUM(monto), 0) as value 
                               FROM ($baseSql) as combined
                               WHERE fecha_v >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                               GROUP BY fecha_v 
                               ORDER BY fecha_v ASC");
    $daily = $stmtDay->fetchAll();

    // 2. Weekly sales (last 12 weeks)
    $stmtWeek = $pdo->query("SELECT DATE(DATE_SUB(fecha_v, INTERVAL WEEKDAY(fecha_v) DAY)) as label, COALESCE(SUM(monto), 0) as value 
                                FROM ($baseSql) as combined
                                WHERE fecha_v >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                                GROUP BY label
                                ORDER BY label ASC");
    $weekly = $stmtWeek->fetchAll();

    // 3. Monthly sales (last 12 months)
    $stmtMonth = $pdo->query("SELECT DATE_FORMAT(fecha_v, '%Y-%m') as label, COALESCE(SUM(monto), 0) as value 
                                 FROM ($baseSql) as combined
                                 WHERE fecha_v >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                                 GROUP BY DATE_FORMAT(fecha_v, '%Y-%m') 
                                 ORDER BY DATE_FORMAT(fecha_v, '%Y-%m') ASC");
    $monthly = $stmtMonth->fetchAll();

    echo json_encode([
        "success" => true,
        "daily" => $daily,
        "weekly" => $weekly,
        "monthly" => $monthly
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
