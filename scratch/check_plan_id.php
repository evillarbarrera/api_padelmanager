<?php
require_once "db.php";
$result = $conn->query("SHOW FULL COLUMNS FROM usuarios LIKE 'plan_id'");
if ($result) {
    while($row = $result->fetch_assoc()) {
        print_r($row);
    }
}
