<?php
require_once "../db.php";

echo "--- INSPECCIÓN DE CONTADORES (AUTO_INCREMENT) ---\n";
$res = $conn->query("SHOW TABLE STATUS LIKE 'pack_jugadores'");
$status = $res->fetch_assoc();
$nextId = $status['Auto_increment'];
echo "El SIGUIENTE ID que se asignará será: " . $nextId . "\n";

$resMax = $conn->query("SELECT MAX(id) as max_id FROM pack_jugadores");
$max = $resMax->fetch_assoc();
echo "El ID MÁXIMO actual en la tabla es: " . $max['max_id'] . "\n";

if ($nextId > ($max['max_id'] + 1)) {
    echo "\n⚠️ ¡ALERTA! Se han detectado IDs borrados recientemente:\n";
    for ($i = $max['max_id'] + 1; $i < $nextId; $i++) {
        echo "ID PERDIDO DETECTADO: $i\n";
    }
} else {
    echo "\nNo se detectan borrados al final de la tabla. Puede que el borrado fuera de un ID más antiguo.\n";
}

echo "\n--- ÚLTIMOS 5 REGISTROS CREADOS (Para referencia) ---\n";
$last = $conn->query("SELECT pj.id, pj.jugador_id, u.nombre, pj.pack_id, p.nombre as pack_n 
                     FROM pack_jugadores pj 
                     JOIN usuarios u ON u.id = pj.jugador_id 
                     JOIN packs p ON p.id = pj.pack_id
                     ORDER BY pj.id DESC LIMIT 5");
while($r = $last->fetch_assoc()) print_r($r);
?>
