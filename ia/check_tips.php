<?php
require_once '../db.php';
header('Content-Type: application/json');

$res = $conn->query("SELECT * FROM tips_diarios_ia ORDER BY fecha DESC LIMIT 5");
$tips = [];
while ($row = $res->fetch_assoc()) {
    $tips[] = $row;
}

echo json_encode(["last_tips" => $tips, "server_date" => date('Y-m-d')]);
