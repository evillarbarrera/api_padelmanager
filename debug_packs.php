<?php
require_once "db.php";
$entrenador_id = 9;

echo "--- PACKS GRUPALES ACTIVOS ---\n";
$sql = "SELECT id, nombre, dia_semana, fecha, hora_inicio, activo, tipo FROM packs WHERE entrenador_id = $entrenador_id AND tipo = 'grupal' AND activo = 1";
$res = $conn->query($sql);
while($row = $res->fetch_assoc()) {
    print_r($row);
}

echo "\n--- RESERVAS PARA LUNES 04 MAYO ---\n";
$sql = "SELECT id, fecha, hora_inicio, tipo, estado, pack_id FROM reservas WHERE entrenador_id = $entrenador_id AND fecha = '2026-05-04'";
$res = $conn->query($sql);
while($row = $res->fetch_assoc()) {
    print_r($row);
}
