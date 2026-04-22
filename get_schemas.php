<?php
require_once "db.php";
$tables = ['usuarios', 'pagos', 'ventas', 'packs', 'entrenadores'];
foreach ($tables as $t) {
    echo "TABLE $t:\n";
    $result = $conn->query("DESCRIBE $t");
    if ($result) {
        while($row = $result->fetch_assoc()) {
            echo "  " . $row['Field'] . " - " . $row['Type'] . "\n";
        }
    } else {
        echo "  Table not found.\n";
    }
    echo "\n";
}
