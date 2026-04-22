<?php
require_once "db.php";

$jugador_id = 3;

echo "--- PACK_JUGADORES for User $jugador_id ---\n";
$sql = "SELECT pj.*, p.nombre, p.entrenador_id, p.sesiones_totales, p.tipo 
        FROM pack_jugadores pj 
        JOIN packs p ON p.id = pj.pack_id 
        WHERE pj.jugador_id = $jugador_id";
$res = $conn->query($sql);
while($row = $res->fetch_assoc()) {
    print_r($row);
}

echo "\n--- RESERVAS for User $jugador_id ---\n";
$sql = "SELECT r.*, p.nombre as pack_nombre
        FROM reservas r
        JOIN reserva_jugadores rj ON rj.reserva_id = r.id
        LEFT JOIN packs p ON p.id = r.pack_id
        WHERE rj.jugador_id = $jugador_id AND r.estado != 'cancelado'";
$res = $conn->query($sql);
while($row = $res->fetch_assoc()) {
    print_r($row);
}
?>
