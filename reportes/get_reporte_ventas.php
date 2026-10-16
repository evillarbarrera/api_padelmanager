<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$club_id = intval($_GET['club_id'] ?? 0);

if (!$club_id) {
    http_response_code(400);
    echo json_encode(["error" => "club_id es requerido"]);
    exit;
}

try {
    /** @var PDO $pdo */

    // 1. Venta Diaria (últimos 30 días) - Filtrado directo en subconsultas
    $sqlDaily = "SELECT fecha_v as label, 
                        COALESCE(SUM(cancha), 0) as value_cancha,
                        COALESCE(SUM(producto), 0) as value_producto
                 FROM (
                     SELECT DATE(v.fecha) as fecha_v, 
                            SUM(CASE WHEN vd.producto_id = 0 THEN vd.cantidad * vd.precio_unitario ELSE 0 END) as cancha,
                            SUM(CASE WHEN vd.producto_id > 0 THEN vd.cantidad * vd.precio_unitario ELSE 0 END) as producto
                     FROM inventario_venta_detalles vd
                     JOIN inventario_ventas v ON vd.venta_id = v.id
                     LEFT JOIN reservas_cancha r ON v.reserva_id = r.id
                     WHERE v.club_id = ? AND v.fecha >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                       AND (v.reserva_id IS NULL OR v.reserva_id = 0 OR (r.id IS NOT NULL AND r.estado != 'Cancelada' AND r.pagado = 1))
                     GROUP BY DATE(v.fecha)
                     UNION ALL
                     SELECT DATE(r.fecha) as fecha_v, r.precio as cancha, 0 as producto
                     FROM reservas_cancha r
                     JOIN canchas c ON r.cancha_id = c.id
                     WHERE c.club_id = ? AND r.fecha >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) 
                       AND r.pagado = 1 AND r.estado != 'Cancelada' AND (r.venta_generada = 0 OR r.venta_generada IS NULL)
                 ) as combined
                 GROUP BY fecha_v 
                 ORDER BY fecha_v ASC";
    $stmtDaily = $pdo->prepare($sqlDaily);
    $stmtDaily->execute([$club_id, $club_id]);
    $daily = $stmtDaily->fetchAll(PDO::FETCH_ASSOC);

    // 2. Venta Semanal (últimos 90 días / 12 semanas) - Filtrado directo en subconsultas
    $sqlWeekly = "SELECT DATE(DATE_SUB(fecha_v, INTERVAL WEEKDAY(fecha_v) DAY)) as label, 
                         COALESCE(SUM(cancha), 0) as value_cancha,
                         COALESCE(SUM(producto), 0) as value_producto
                  FROM (
                      SELECT DATE(v.fecha) as fecha_v, 
                             SUM(CASE WHEN vd.producto_id = 0 THEN vd.cantidad * vd.precio_unitario ELSE 0 END) as cancha,
                             SUM(CASE WHEN vd.producto_id > 0 THEN vd.cantidad * vd.precio_unitario ELSE 0 END) as producto
                      FROM inventario_venta_detalles vd
                      JOIN inventario_ventas v ON vd.venta_id = v.id
                      LEFT JOIN reservas_cancha r ON v.reserva_id = r.id
                      WHERE v.club_id = ? AND v.fecha >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                        AND (v.reserva_id IS NULL OR v.reserva_id = 0 OR (r.id IS NOT NULL AND r.estado != 'Cancelada' AND r.pagado = 1))
                      GROUP BY DATE(v.fecha)
                      UNION ALL
                      SELECT DATE(r.fecha) as fecha_v, r.precio as cancha, 0 as producto
                      FROM reservas_cancha r
                      JOIN canchas c ON r.cancha_id = c.id
                      WHERE c.club_id = ? AND r.fecha >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) 
                        AND r.pagado = 1 AND r.estado != 'Cancelada' AND (r.venta_generada = 0 OR r.venta_generada IS NULL)
                  ) as combined
                  GROUP BY label
                  ORDER BY label ASC";
    $stmtWeekly = $pdo->prepare($sqlWeekly);
    $stmtWeekly->execute([$club_id, $club_id]);
    $weekly = $stmtWeekly->fetchAll(PDO::FETCH_ASSOC);

    // 3. Venta Mensual (últimos 12 meses) - Filtrado directo en subconsultas
    $sqlMonthly = "SELECT DATE_FORMAT(fecha_v, '%Y-%m') as label, 
                          COALESCE(SUM(cancha), 0) as value_cancha,
                          COALESCE(SUM(producto), 0) as value_producto
                   FROM (
                       SELECT DATE(v.fecha) as fecha_v, 
                              SUM(CASE WHEN vd.producto_id = 0 THEN vd.cantidad * vd.precio_unitario ELSE 0 END) as cancha,
                              SUM(CASE WHEN vd.producto_id > 0 THEN vd.cantidad * vd.precio_unitario ELSE 0 END) as producto
                       FROM inventario_venta_detalles vd
                       JOIN inventario_ventas v ON vd.venta_id = v.id
                       LEFT JOIN reservas_cancha r ON v.reserva_id = r.id
                       WHERE v.club_id = ? AND v.fecha >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                         AND (v.reserva_id IS NULL OR v.reserva_id = 0 OR (r.id IS NOT NULL AND r.estado != 'Cancelada' AND r.pagado = 1))
                       GROUP BY DATE(v.fecha)
                       UNION ALL
                       SELECT DATE(r.fecha) as fecha_v, r.precio as cancha, 0 as producto
                       FROM reservas_cancha r
                       JOIN canchas c ON r.cancha_id = c.id
                       WHERE c.club_id = ? AND r.fecha >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) 
                         AND r.pagado = 1 AND r.estado != 'Cancelada' AND (r.venta_generada = 0 OR r.venta_generada IS NULL)
                   ) as combined
                   GROUP BY DATE_FORMAT(fecha_v, '%Y-%m') 
                   ORDER BY DATE_FORMAT(fecha_v, '%Y-%m') ASC";
    $stmtMonthly = $pdo->prepare($sqlMonthly);
    $stmtMonthly->execute([$club_id, $club_id]);
    $monthly = $stmtMonthly->fetchAll(PDO::FETCH_ASSOC);

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
