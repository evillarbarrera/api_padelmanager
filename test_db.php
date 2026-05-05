<?php
require_once "db.php";
$res = $conn->query("DESCRIBE torneo_participantes");
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . " - " . $row['Type'] . "\n";
}
