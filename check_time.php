<?php
require_once "db.php";
echo "PHP Time: " . date('Y-m-d H:i:s') . "<br>";
$res = $conn->query("SELECT NOW() as db_time");
$row = $res->fetch_assoc();
echo "DB Time: " . $row['db_time'] . "<br>";
?>
