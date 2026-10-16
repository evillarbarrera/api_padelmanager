<?php
require_once "db.php";
$res = $conn->query("SELECT id, cancha_id, fecha, hora_inicio, hora_fin, estado, nombre_externo FROM reservas_cancha WHERE fecha = '2026-10-06'");
$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
echo json_encode($rows, JSON_PRETTY_PRINT);
?>
