<?php
require_once '../db.php';
header('Content-Type: application/json');

$hoy = date('Y-m-d');
$refresh = isset($_GET['refresh']) && $_GET['refresh'] == '1';

if ($refresh) {
    echo "Refrescando hoy ($hoy)...\n";
    $conn->query("DELETE FROM tips_diarios_ia WHERE fecha = '$hoy'");
}

$res = $conn->query("SELECT * FROM tips_diarios_ia ORDER BY fecha DESC LIMIT 5");
$tips = [];
while ($row = $res->fetch_assoc()) {
    $tips[] = $row;
}

echo json_encode([
    "hoy" => $hoy,
    "last_tips" => $tips
], JSON_PRETTY_PRINT);
