<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require_once "../db.php";

$res = $conn->query("SELECT id, nombre, landing_slug, landing_publicado, telefono FROM clubes");
$clubes = [];
while ($r = $res->fetch_assoc()) {
    $clubes[] = $r;
}

echo json_encode($clubes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
