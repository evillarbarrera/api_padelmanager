<?php
require_once "db.php";
$res = $conn->query("DESCRIBE recordatorios_programados");
while($row = $res->fetch_assoc()) {
    print_r($row);
}
