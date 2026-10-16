<?php
// debug_data.php
require_once "db.php";
$res_packs = $conn->query("SELECT * FROM packs");
$packs = [];
while($row = $res_packs->fetch_assoc()) $packs[] = $row;

$res_pj = $conn->query("SELECT * FROM pack_jugadores");
$pj = [];
while($row = $res_pj->fetch_assoc()) $pj[] = $row;

echo json_encode([
    "packs" => $packs,
    "pack_jugadores" => $pj
], JSON_PRETTY_PRINT);
?>
