<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Content-Type: text/plain");

require_once "../db.php";

$table = 'pack_jugadores';
$sql = "SHOW CREATE TABLE $table";
$result = $conn->query($sql);

if (!$result) {
    die("Error showing table: " . $conn->error);
}

$row = $result->fetch_array();
$createSql = $row[1];

echo "Original Schema:\n$createSql\n\n";

// Regex to find UNIQUE KEY `name` (...)
// Example: UNIQUE KEY `pack_id` (`pack_id`,`jugador_id`)
preg_match_all('/UNIQUE KEY [`"]?(\w+)[`"]?/', $createSql, $matches);

if (!empty($matches[1])) {
    foreach ($matches[1] as $indexName) {
        if ($indexName === 'PRIMARY') continue; // Should not match UNIQUE KEY usually, but safety first

        echo "Found Unique Key: $indexName\n";
        $drop = "ALTER TABLE $table DROP INDEX `$indexName`";
        echo "Executing: $drop ... ";
        if ($conn->query($drop)) {
            echo "DROPPED OK\n";
        } else {
            echo "FAIL: " . $conn->error . "\n";
        }
    }
    echo "\nPlease try buying the pack again now.";
} else {
    echo "No UNIQUE constraints found in the text definition. Database seems clear.";
}
?>
