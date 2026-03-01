<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Content-Type: text/plain");

require_once "../db.php";

$table = 'pack_jugadores';
$sql = "SHOW CREATE TABLE $table";
$res = $conn->query($sql);
$row = $res->fetch_array();
$createSql = $row[1];

echo "Analyzing Schema...\n";

// 1. Find Foreign Key usage on pack_id
// CONSTRAINT `fk_name` FOREIGN KEY (`pack_id`) REFERENCES ...
$fkName = null;
if (preg_match('/CONSTRAINT [`"]?(\w+)[`"]? FOREIGN KEY \([`"]?pack_id[`"]?\)/', $createSql, $matches)) {
    $fkName = $matches[1];
    echo "Found Foreign Key: $fkName\n";
} else {
    echo "No FK found specifically on pack_id (or regex failed). Might be defined differently.\n";
}

// 2. Prepare logic
if ($fkName) {
    echo "Attempting to fix...\n";
    
    // Drop FK
    $sql1 = "ALTER TABLE $table DROP FOREIGN KEY `$fkName`";
    echo "1. $sql1 ... ";
    if ($conn->query($sql1)) echo "OK\n"; else echo "FAIL: " . $conn->error . "\n";
    
    // Drop Index
    $sql2 = "ALTER TABLE $table DROP INDEX `pack_id`";
    echo "2. $sql2 ... ";
    if ($conn->query($sql2)) echo "OK\n"; else echo "FAIL: " . $conn->error . "\n";
    
    // Optional: Re-add FK (will create non-unique index)
    // We assume it references `packs`(`id`)
    $sql3 = "ALTER TABLE $table ADD CONSTRAINT `$fkName` FOREIGN KEY (`pack_id`) REFERENCES `packs` (`id`) ON DELETE BOOLEAN"; 
    // Wait, syntax "ON DELETE BOOLEAN" is wrong, usually CASCADE or RESTRICT.
    // Safest is to just add a normal index first.
    
    $sql3 = "ALTER TABLE $table ADD INDEX `pack_id` (`pack_id`)";
    echo "3. Adding non-unique index: $sql3 ... ";
    if ($conn->query($sql3)) echo "OK\n"; else echo "FAIL: " . $conn->error . "\n";
    
    // Now re-add FK
    $sql4 = "ALTER TABLE $table ADD CONSTRAINT `$fkName` FOREIGN KEY (`pack_id`) REFERENCES `packs` (`id`)";
    echo "4. Restoring FK: $sql4 ... ";
    if ($conn->query($sql4)) echo "OK\n"; else echo "FAIL: " . $conn->error . "\n";
    
    echo "\nDONE. Try buying now.";
} else {
    echo "Could not auto-detect FK name. Please try running this manually in SQL:\n";
    echo "SHOW CREATE TABLE pack_jugadores;\n";
    echo "-- Find the CONSTRAINT name for pack_id\n";
    echo "ALTER TABLE pack_jugadores DROP FOREIGN KEY [constraint_name];\n";
    echo "ALTER TABLE pack_jugadores DROP INDEX pack_id;\n";
}
?>
