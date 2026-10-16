<?php
require_once "db.php";
$stmt = $pdo->query("SHOW COLUMNS FROM reservas_consumos");
$rc = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->query("SHOW COLUMNS FROM inventario_venta_detalles");
$ivd = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(["reservas_consumos" => $rc, "inventario_venta_detalles" => $ivd]);
