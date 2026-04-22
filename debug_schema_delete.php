<?php
require_once "db.php";
$tables = ['inscripciones_grupales', 'reservas_cancha', 'direcciones_usuarios', 'direcciones'];
foreach ($tables as $t) {
    echo "TABLE $t:\n";
    $result = $conn->query("DESCRIBE $t");
    if ($result) {
        while($row = $result->fetch_assoc()) {
            echo "  " . $row['Field'] . "\n";
        }
    } else {
        echo "  (No existe)\n";
    }
    echo "\n";
}
