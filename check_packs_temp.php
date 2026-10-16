<?php
require_once "db.php";
$result = $conn->query("DESCRIBE packs");
if ($result) {
    while($row = $result->fetch_assoc()) {
        echo $row['Field'] . " - " . $row['Type'] . "\n";
    }
} else {
    echo "Packs table not found.";
}
