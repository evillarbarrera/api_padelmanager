<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
require_once "../db.php";
$res = $conn->query("DESCRIBE clubes");
echo json_encode($res->fetch_all(MYSQLI_ASSOC));
?>
