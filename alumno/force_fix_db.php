<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Content-Type: text/plain");

require_once "../db.php";

$dbName = '';
// get db name
if ($result = $conn->query("SELECT DATABASE()")) {
    $row = $result->fetch_row();
    $dbName = $row[0];
}

echo "Database: $dbName\n";

// Find constraints
$sql = "
    SELECT CONSTRAINT_NAME 
    FROM information_schema.TABLE_CONSTRAINTS 
    WHERE TABLE_SCHEMA = '$dbName' 
    AND TABLE_NAME = 'pack_jugadores' 
    AND CONSTRAINT_TYPE = 'UNIQUE'
";

$result = $conn->query($sql);
$constraints = [];

while ($row = $result->fetch_assoc()) {
    $constraints[] = $row['CONSTRAINT_NAME'];
}

if (empty($constraints)) {
    // Try looking at indexes if constraints check failed
    $sqlIndex = "SHOW INDEX FROM pack_jugadores WHERE Non_unique = 0 AND Key_name != 'PRIMARY'";
    $resIndex = $conn->query($sqlIndex);
    while ($row = $resIndex->fetch_assoc()) {
        $constraints[] = $row['Key_name'];
    }
}

$constraints = array_unique($constraints);

if (empty($constraints)) {
    echo "NO UNIQUE CONSTRAINTS FOUND. You should be able to buy packs.\n";
} else {
    echo "Found constraints: " . implode(", ", $constraints) . "\n";
    foreach ($constraints as $name) {
        $dropSql = "ALTER TABLE pack_jugadores DROP INDEX `$name`";
        echo "Executing: $dropSql ... ";
        if ($conn->query($dropSql)) {
            echo "SUCCESS\n";
        } else {
            echo "FAILED: " . $conn->error . "\n";
        }
    }
}
?>
