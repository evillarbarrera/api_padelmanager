<?php
require_once "db.php";
echo "--- COLUMNS OF cancha_horarios_config ---\n";
$res = $conn->query("DESCRIBE cancha_horarios_config");
if ($res) {
    while($row = $res->fetch_assoc()) {
        echo $row['Field'] . " | " . $row['Type'] . "\n";
    }
} else {
    echo "Table cancha_horarios_config does not exist\n";
}

echo "\n--- DATA IN cancha_horarios_config ---\n";
$resData = $conn->query("SELECT * FROM cancha_horarios_config");
if ($resData) {
    while($row = $resData->fetch_assoc()) {
        print_r($row);
    }
}
?>
