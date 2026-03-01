<?php
require_once "db.php";
$email = 'e.villarbarrera@gmail.com';
$res = $conn->query("SELECT id FROM usuarios WHERE email = '$email'");
$user = $res->fetch_assoc();
$jugador_id = $user['id'];

echo "User ID: $jugador_id\n";

$sql = "
    SELECT 
        p.id, p.nombre, p.sesiones_totales, pj.id as pj_id,
        (SELECT COUNT(*) FROM pack_jugadores pj2 WHERE pj2.pack_id = p.id AND pj2.jugador_id = $jugador_id) as compras
    FROM packs p
    JOIN pack_jugadores pj ON pj.pack_id = p.id
    WHERE pj.jugador_id = $jugador_id
";
$resPacks = $conn->query($sql);
while($p = $resPacks->fetch_assoc()) {
    echo "Pack: " . $p['nombre'] . " (Total per pack: " . $p['sesiones_totales'] . ", Purchases: " . $p['compras'] . ")\n";
    $pack_id = $p['id'];
    $sqlRes = "SELECT id, fecha, hora_inicio, estado FROM reservas r JOIN reserva_jugadores rj ON rj.reserva_id = r.id WHERE rj.jugador_id = $jugador_id AND r.pack_id = $pack_id AND r.estado != 'cancelado'";
    $resRes = $conn->query($sqlRes);
    echo "  Reservations (not cancelled):\n";
    while($r = $resRes->fetch_assoc()) {
        echo "    - ID: " . $r['id'] . " Date: " . $r['fecha'] . " State: " . $r['estado'] . "\n";
    }
}
?>
