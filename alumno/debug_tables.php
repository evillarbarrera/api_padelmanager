<?php
require_once "../db.php";
$sql = "DESCRIBE pack_jugadores";
$result = $conn->query($sql);
while($row = $result->fetch_assoc()){
    print_r($row);
    echo "<br>";
}
?>
