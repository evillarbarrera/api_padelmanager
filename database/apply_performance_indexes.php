<?php
/**
 * PADELBLOX - DATABASE PERFORMANCE INDEX MIGRATION
 * Applies high-performance B-Tree indexes for ultra-fast reports and sales queries.
 */

require_once __DIR__ . '/../db.php';

header("Content-Type: application/json; charset=utf-8");

$indexes = [
    "CREATE INDEX idx_iv_club_fecha ON inventario_ventas(club_id, fecha)",
    "CREATE INDEX idx_iv_reserva ON inventario_ventas(reserva_id)",
    "CREATE INDEX idx_ivd_venta ON inventario_venta_detalles(venta_id)",
    "CREATE INDEX idx_ivd_producto ON inventario_venta_detalles(producto_id)",
    "CREATE INDEX idx_rc_fecha_pagado ON reservas_cancha(fecha, pagado)",
    "CREATE INDEX idx_u_session_token ON usuarios(current_session_token)"
];

$results = [];

foreach ($indexes as $sql) {
    try {
        if ($conn->query($sql)) {
            $results[] = "OK: " . $sql;
        } else {
            $results[] = "NOTE (Already exists or skipped): " . $conn->error;
        }
    } catch (Throwable $e) {
        $results[] = "NOTE: " . $e->getMessage();
    }
}

echo json_encode([
    "success" => true,
    "message" => "Índices de alto rendimiento procesados",
    "details" => $results
], JSON_PRETTY_PRINT);
