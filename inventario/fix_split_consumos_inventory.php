<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once "../db.php";

$dry_run = isset($_GET['dry_run']) && $_GET['dry_run'] === 'true';

try {
    $pdo->beginTransaction();

    $report = [
        "consumos_actualizados" => 0,
        "ventas_detalles_actualizados" => 0,
        "stock_devuelto" => [],
        "detalles" => []
    ];

    // 1. Identificar en reservas_consumos los productos divididos con cantidad = 1
    // (Ejemplo: 4 registros para el mismo producto_id en la misma reserva con cantidad = 1)
    $stmtC = $pdo->query("SELECT reserva_id, producto_id, COUNT(*) as cnt, SUM(cantidad) as sum_cant 
                          FROM reservas_consumos 
                          WHERE cantidad >= 1 AND producto_id > 0 
                          GROUP BY reserva_id, producto_id 
                          HAVING cnt > 1 AND sum_cant >= cnt");
    $groupsC = $stmtC->fetchAll(PDO::FETCH_ASSOC);

    foreach ($groupsC as $g) {
        $resId = (int)$g['reserva_id'];
        $pId = (int)$g['producto_id'];
        $cnt = (int)$g['cnt'];
        $nuevaCant = round(1 / $cnt, 4); // Ej: 0.25 para 4 jugadores

        $stmtUpdC = $pdo->prepare("UPDATE reservas_consumos 
                                   SET cantidad = ? 
                                   WHERE reserva_id = ? AND producto_id = ? AND cantidad >= 1");
        $stmtUpdC->execute([$nuevaCant, $resId, $pId]);
        $report["consumos_actualizados"] += $stmtUpdC->rowCount();
    }

    // 2. Identificar en inventario_venta_detalles los ítems divididos
    $stmtV = $pdo->query("SELECT vd.venta_id, vd.producto_id, v.club_id, COUNT(*) as cnt, SUM(vd.cantidad) as sum_cant
                          FROM inventario_venta_detalles vd
                          JOIN inventario_ventas v ON vd.venta_id = v.id
                          WHERE vd.cantidad >= 1 AND vd.producto_id > 0
                          GROUP BY vd.venta_id, vd.producto_id
                          HAVING cnt > 1 AND sum_cant >= cnt");
    $groupsV = $stmtV->fetchAll(PDO::FETCH_ASSOC);

    foreach ($groupsV as $gv) {
        $vId = (int)$gv['venta_id'];
        $pId = (int)$gv['producto_id'];
        $clubId = (int)$gv['club_id'];
        $cnt = (int)$gv['cnt'];
        $sumCant = floatval($gv['sum_cant']);

        $nuevaCant = round(1 / $cnt, 4);
        $excesoRestituir = $sumCant - 1.0; // Ej: 4 tarros registrados - 1 real = 3 a devolver

        if ($excesoRestituir > 0) {
            // Actualizar las filas de detalles de la venta
            $stmtUpdVD = $pdo->prepare("UPDATE inventario_venta_detalles 
                                        SET cantidad = ? 
                                        WHERE venta_id = ? AND producto_id = ? AND cantidad >= 1");
            $stmtUpdVD->execute([$nuevaCant, $vId, $pId]);
            $report["ventas_detalles_actualizados"] += $stmtUpdVD->rowCount();

            // Devolver stock al producto
            $stmtStock = $pdo->prepare("UPDATE inventario_productos 
                                        SET stock_actual = stock_actual + ? 
                                        WHERE id = ?");
            $stmtStock->execute([$excesoRestituir, $pId]);

            // Registrar movimiento en kardex
            $stmtMov = $pdo->prepare("INSERT INTO inventario_movimientos (club_id, producto_id, tipo, cantidad, motivo, referencia_id) 
                                      VALUES (?, ?, 'entrada', ?, 'Ajuste Corrección División Pelotas', ?)");
            $stmtMov->execute([$clubId, $pId, $excesoRestituir, $vId]);

            if (!isset($report["stock_devuelto"][$pId])) {
                $report["stock_devuelto"][$pId] = 0;
            }
            $report["stock_devuelto"][$pId] += $excesoRestituir;

            $report["detalles"][] = [
                "venta_id" => $vId,
                "producto_id" => $pId,
                "registros_divididos" => $cnt,
                "stock_devuelto" => $excesoRestituir
            ];
        }
    }

    if ($dry_run) {
        $pdo->rollBack();
        echo json_encode([
            "success" => true,
            "mode" => "SIMULACION (dry_run=true, no se aplicaron cambios permanentemente)",
            "reporte" => $report
        ]);
    } else {
        $pdo->commit();
        echo json_encode([
            "success" => true,
            "mode" => "EJECUTADO CORRECTAMENTE (cambios guardados y stock devuelto)",
            "reporte" => $report
        ]);
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
