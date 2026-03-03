<?php
require_once "db.php";
$result = $conn->query("DESCRIBE packs");
while($row = $result->fetch_assoc()) {
    print_r($row);
}
