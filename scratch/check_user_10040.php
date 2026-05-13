<?php
require_once "../db.php";
$jId = 10040;

echo "--- DATOS DEL JUGADOR $jId ---\n";
$u = $conn->query("SELECT nombre, usuario FROM usuarios WHERE id = $jId")->fetch_assoc();
print_r($u);

echo "\n--- PACKS ACTUALES EN pack_jugadores ---\n";
$pj = $conn->query("SELECT pj.*, p.nombre as pack_nombre FROM pack_jugadores pj JOIN packs p ON p.id = pj.pack_id WHERE pj.jugador_id = $jId");
while($row = $pj->fetch_assoc()) print_r($row);

echo "\n--- REFERENCIAS EN RESERVAS (Huerfanas o no) ---\n";
$res = $conn->query("SELECT r.id, r.pack_id, r.pack_jugador_id, r.fecha, p.nombre as pack_nombre 
                    FROM reservas r 
                    JOIN reserva_jugadores rj ON rj.reserva_id = r.id
                    JOIN packs p ON p.id = r.pack_id
                    WHERE rj.jugador_id = $jId 
                    ORDER BY r.fecha DESC LIMIT 10");
while($row = $res->fetch_assoc()) print_r($row);

echo "\n--- REFERENCIAS EN INSCRIPCIONES GRUPALES ---\n";
$ins = $conn->query("SELECT ig.*, p.nombre as pack_nombre 
                    FROM inscripciones_grupales ig 
                    JOIN packs p ON p.id = ig.pack_id
                    WHERE ig.jugador_id = $jId");
while($row = $ins->fetch_assoc()) print_r($row);
?>
