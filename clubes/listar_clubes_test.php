<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require_once "../db.php";

$res = $conn->query("SELECT id, nombre, landing_slug, landing_publicado, telefono, logo_url FROM clubes");
$clubes = [];
while ($r = $res->fetch_assoc()) {
    $cid = intval($r['id']);
    $rC = $conn->query("SELECT id, nombre, club_id, activa, precio_hora, precio_90 FROM canchas WHERE club_id = $cid");
    $canchas = [];
    while ($c = $rC->fetch_assoc()) {
        $canchas[] = $c;
    }
    $r['canchas'] = $canchas;
    $clubes[] = $r;
}

// También listar canchas huérfanas o con club_id = 0
$rOrphans = $conn->query("SELECT id, nombre, club_id, activa, precio_hora FROM canchas WHERE club_id NOT IN (SELECT id FROM clubes)");
$orphans = [];
if ($rOrphans) {
    while ($o = $rOrphans->fetch_assoc()) {
        $orphans[] = $o;
    }
}

echo json_encode(["clubes" => $clubes, "canchas_huerfanas" => $orphans], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

