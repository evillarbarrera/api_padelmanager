<?php
require_once '../db.php';
header('Content-Type: application/json');

$res = $conn->query("SHOW CREATE TABLE fcm_tokens");
$schema = $res->fetch_assoc();

$res2 = $conn->query("SHOW CREATE TABLE notificaciones");
$schema2 = $res2->fetch_assoc();

echo json_encode(["fcm_tokens" => $schema, "notificaciones" => $schema2]);
