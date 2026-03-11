<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Content-Type: text/plain");

require_once "../db.php";

$table = 'reserva_jugadores';
$sql = "SHOW CREATE TABLE $table";
$res = $conn->query($sql);

if (!$res) {
    die("Error access table: " . $conn->error);
}

$row = $res->fetch_array();
$createSql = $row[1];

echo "Current Schema for $table:\n\n";
echo $createSql . "\n\n";

// Find FK to reservas
// Usually looks like: CONSTRAINT `reserva_jugadores_ibfk_1` FOREIGN KEY (`reserva_id`) REFERENCES `reservas` (`id`)
$fkName = null;
if (preg_match('/CONSTRAINT [`"]?(\w+)[`"]? FOREIGN KEY \([`"]?reserva_id[`"]?\)/', $createSql, $matches)) {
    $fkName = $matches[1];
    echo "Found Foreign Key: $fkName\n";
} else {
    echo "No FK found on reserva_id (regex mismatch).\n";
    // Search broadly
    if (strpos($createSql, 'FOREIGN KEY (`reserva_id`)') !== false) {
        echo "Found FK on reserva_id but couldn't get the name easily.\n";
    }
}

if ($fkName) {
    echo "Attempting to add ON DELETE CASCADE...\n";
    
    // 1. Drop old FK
    $sql1 = "ALTER TABLE $table DROP FOREIGN KEY `$fkName`";
    echo "Step 1: $sql1 ... ";
    if ($conn->query($sql1)) {
        echo "OK\n";
    } else {
        echo "FAIL: " . $conn->error . "\n";
    }
    
    // 2. Add new FK with CASCADE
    $sql2 = "ALTER TABLE $table ADD CONSTRAINT `$fkName` FOREIGN KEY (`reserva_id`) REFERENCES `reservas` (`id`) ON DELETE CASCADE";
    echo "Step 2: $sql2 ... ";
    if ($conn->query($sql2)) {
        echo "OK\n";
    } else {
        echo "FAIL: " . $conn->error . "\n";
    }
    
    echo "\nFIX COMPLETED. Now you should be able to delete from 'reservas' without errors.";
} else {
    echo "Please provide the FK name manually if you see it in the schema above.";
}
?>
