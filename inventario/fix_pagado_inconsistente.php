<?php
/**
 * fix_pagado_inconsistente.php
 * 
 * Corrige reservas donde pago_p1-p4 están todos en 1 pero pagado = 0
 * (o donde todos son 0 pero pagado = 1).
 * 
 * EJECUTAR UNA SOLA VEZ desde el servidor.
 * URL: https://padelblox.cl/api/inventario/fix_pagado_inconsistente.php
 */

header("Content-Type: application/json; charset=UTF-8");
require_once '../db.php';

try {
    // Caso 1: Todos los jugadores tienen pago = 1, pero pagado global = 0 → setear pagado = 1
    $sql_fix_pagado = "
        UPDATE reservas 
        SET pagado = 1
        WHERE pagado = 0
          AND pago_p1 = 1
          AND pago_p2 = 1
          AND pago_p3 = 1
          AND pago_p4 = 1
          AND estado = 'Reservada'
    ";
    $stmt = $pdo->prepare($sql_fix_pagado);
    $stmt->execute();
    $fixed_to_paid = $stmt->rowCount();

    // Caso 2: Metodo proporcional y al menos 1 jugador tiene pago = 0, pero pagado global = 1 → setear pagado = 0
    // Solo aplica para reservas donde hay jugadores asignados con pago pendiente
    $sql_fix_debe = "
        UPDATE reservas
        SET pagado = 0
        WHERE pagado = 1
          AND metodo_pago = 'proporcional'
          AND (pago_p1 = 0 OR pago_p2 = 0 OR pago_p3 = 0 OR pago_p4 = 0)
          AND estado = 'Reservada'
          AND (nombre_externo IS NOT NULL OR usuario_id IS NOT NULL OR jugador2_id IS NOT NULL OR jugador3_id IS NOT NULL OR jugador4_id IS NOT NULL)
    ";
    $stmt2 = $pdo->prepare($sql_fix_debe);
    $stmt2->execute();
    $fixed_to_debe = $stmt2->rowCount();

    // Reporte de lo que se encontraba inconsistente
    $sql_report = "
        SELECT id, nombre_externo, pago_p1, pago_p2, pago_p3, pago_p4, pagado, metodo_pago
        FROM reservas
        WHERE estado = 'Reservada'
        ORDER BY id DESC
        LIMIT 20
    ";
    $stmt3 = $pdo->prepare($sql_report);
    $stmt3->execute();
    $sample = $stmt3->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "message" => "Fix completado",
        "reservas_corregidas_a_PAGADO" => $fixed_to_paid,
        "reservas_corregidas_a_DEBE" => $fixed_to_debe,
        "muestra_ultimas_20" => $sample
    ], JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
