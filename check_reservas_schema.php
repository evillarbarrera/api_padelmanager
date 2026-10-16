<?php
require_once "db.php";
$res = $conn->query("DESCRIBE reservas");
while($row = $res->fetch_assoc()) {
    print_r($row);
}
?>
