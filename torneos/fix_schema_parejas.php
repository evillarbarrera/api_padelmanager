<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Content-Type: text/plain");

if (!file_exists("../db.php")) {
    die("Error: ../db.php does not exist.");
}
require_once "../db.php";

if ($conn->connect_error) {
    die("Error: Connection failed: " . $conn->connect_error);
}

echo "Starting Update Schema Process...\n";

// Function to safely add columns if they don't exist
function ensureColumn($conn, $table, $column, $definition) {
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($res && $res->num_rows == 0) {
        $sql = "ALTER TABLE `$table` ADD `$column` $definition";
        if ($conn->query($sql)) {
            echo "SUCCESS: Added column '$column' to table '$table'.\n";
        } else {
            echo "ERROR: Failed to add '$column' to '$table': " . $conn->error . "\n";
        }
    } else {
        echo "INFO: Column '$column' already exists in table '$table'.\n";
    }
}

// 1. Add Couple IDs to torneo_partidos
// These are critical to distinguish same player in different teams
ensureColumn($conn, 'torneo_partidos', 'pareja1_id', "INT DEFAULT NULL AFTER ronda");
ensureColumn($conn, 'torneo_partidos', 'pareja2_id', "INT DEFAULT NULL AFTER pareja1_id");

// 2. Add Couple IDs to torneo_partidos_v2 (if exists) just in case
$resV2 = $conn->query("SHOW TABLES LIKE 'torneo_partidos_v2'");
if ($resV2 && $resV2->num_rows > 0) {
    ensureColumn($conn, 'torneo_partidos_v2', 'pareja1_id', "INT DEFAULT NULL");
    ensureColumn($conn, 'torneo_partidos_v2', 'pareja2_id', "INT DEFAULT NULL");
}

echo "Schema Update Complete.\n";
echo "Now verifying indices for performance...\n";

// Optional: Add index for faster lookups
try {
    $conn->query("CREATE INDEX idx_pareja1 ON torneo_partidos(pareja1_id)");
    $conn->query("CREATE INDEX idx_pareja2 ON torneo_partidos(pareja2_id)");
    echo "SUCCESS: Added indices for pareja1_id and pareja2_id.\n";
} catch (Exception $e) {
    echo "INFO: Indices might already exist or failed: " . $e->getMessage() . "\n";
}

echo "Done.";
?>
