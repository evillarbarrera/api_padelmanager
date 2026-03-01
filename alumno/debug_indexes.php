<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Content-Type: application/json");

require_once "../db.php";

$table = 'pack_jugadores';
$sql = "SHOW INDEX FROM $table";
$result = $conn->query($sql);

$indexes = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $indexes[] = $row;
    }
} else {
    $indexes = ["error" => $conn->error];
}

echo json_encode($indexes, JSON_PRETTY_PRINT);
?>
