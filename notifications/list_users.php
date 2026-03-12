<?php
require_once '../db.php';
header('Content-Type: application/json');

$res = $conn->query("SELECT id, nombre, usuario, rol FROM usuarios ORDER BY id DESC LIMIT 10");
$users = [];
while ($row = $res->fetch_assoc()) {
    $users[] = $row;
}

echo json_encode($users, JSON_PRETTY_PRINT);
