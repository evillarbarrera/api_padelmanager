<?php
// ================= CORS SIMPLE =================
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// ================= DB =================
require_once __DIR__ . '/db.php';

// ================= QUERY =================
$sql = "SELECT * FROM usuarios";
$result = $conn->query($sql);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        "error" => $conn->error
    ]);
    exit;
}

$usuarios = [];
while ($row = $result->fetch_assoc()) {
    $usuarios[] = $row;
}

echo json_encode($usuarios);
