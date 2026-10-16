<?php
require_once "db.php";
echo "--- COLUMNS OF reservas_cancha ---\n";
$res = $conn->query("DESCRIBE reservas_cancha");
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . " | " . $row['Type'] . "\n";
}
echo "--- END ---\n";
?>
