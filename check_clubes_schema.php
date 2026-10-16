<?php
require_once "db.php";
$res = $conn->query("DESCRIBE clubes");
while($row = $res->fetch_assoc()) {
    print_r($row);
}
?>
