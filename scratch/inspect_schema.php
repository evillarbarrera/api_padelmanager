<?php
require_once "../db.php";
$result = $conn->query("DESCRIBE pack_jugadores");
echo "<table border='1'>";
while($row = $result->fetch_assoc()){
    echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td></tr>";
}
echo "</table>";
?>
