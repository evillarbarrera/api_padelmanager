<?php
require_once "db.php";
$entrenador_id = 9;
$sql = "SELECT id, fecha, hora_inicio, tipo, estado FROM reservas WHERE entrenador_id = ? AND fecha = '2026-05-02' AND estado != 'cancelado'";
$result = $conn->query($sql);
$data = [];
while($row = $result->fetch_assoc()) { $data[] = $row; }
echo "RESERVAS ACTIVAS: " . json_encode($data) . "\n\n";

$sql_p = "SELECT id, nombre, dia_semana, hora_inicio, activo FROM packs WHERE entrenador_id = ? AND tipo = 'grupal' AND activo = 1";
$result_p = $conn->query($sql_p);
$packs = [];
while($row = $result_p->fetch_assoc()) { $packs[] = $row; }
echo "PACKS GRUPALES: " . json_encode($packs) . "\n";
?>
