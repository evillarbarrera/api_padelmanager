<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Content-Type: text/plain");
require_once "../db.php";

$table = 'disponibilidad_profesor';
$sql = "DESCRIBE $table";
$result = $conn->query($sql);

if ($result) {
    while($row = $result->fetch_assoc()){
        print_r($row);
    }
} else {
    echo "Error DESCRIBE: " . $conn->error;
}

echo "\n\n--- INDEXES ---\n";
$sqlIdx = "SHOW INDEX FROM $table";
$resIdx = $conn->query($sqlIdx);
if ($resIdx) {
    while($row = $resIdx->fetch_assoc()){
        print_r($row);
    }
} else {
    echo "Error SHOW INDEX: " . $conn->error;
}
?>
