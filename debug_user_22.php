<?php
require_once "db.php";

$jugador_id = 22;

echo "--- PACK_JUGADORES ---\n";
$res = $conn->query("SELECT pj.*, p.nombre, p.sesiones_totales 
                     FROM pack_jugadores pj 
                     JOIN packs p ON pj.pack_id = p.id 
                     WHERE pj.jugador_id = $jugador_id");
while ($row = $res->fetch_assoc()) {
    print_r($row);
    $pj_id = $row['id'];
    $p_id = $row['pack_id'];
    
    echo "  --- RESERVAS para este Pack Jugador ID ($pj_id) ---\n";
    $res2 = $conn->query("SELECT r.* FROM reservas r WHERE r.pack_jugador_id = $pj_id AND r.estado != 'cancelado'");
    while ($r2 = $res2->fetch_assoc()) {
        echo "    Reserva ID: " . $r2['id'] . " | Fecha: " . $r2['fecha'] . " | Estado: " . $r2['estado'] . "\n";
    }
}

echo "\n--- RESERVAS (TODAS) para el Jugador 22 ---\n";
$res3 = $conn->query("SELECT r.*, p.nombre as pack_nombre 
                      FROM reservas r 
                      LEFT JOIN packs p ON r.pack_id = p.id 
                      JOIN reserva_jugadores rj ON r.id = rj.reserva_id 
                      WHERE rj.jugador_id = $jugador_id AND r.estado != 'cancelado'");
while ($row3 = $res3->fetch_assoc()) {
    echo "ID: " . $row3['id'] . " | Pack: " . $row3['pack_nombre'] . " | PJ_ID: " . $row3['pack_jugador_id'] . " | Fecha: " . $row3['fecha'] . " | Hora: " . $row3['hora_inicio'] . " | Estado: " . $row3['estado'] . "\n";
}
