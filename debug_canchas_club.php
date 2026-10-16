<?php
require_once "db.php";
$res = $conn->query("SELECT id, nombre, club_id FROM canchas");
while ($r = $res->fetch_assoc()) {
    echo "Cancha ID: {$r['id']} | Nombre: {$r['nombre']} | Club ID: {$r['club_id']}<br>";
}
?>
