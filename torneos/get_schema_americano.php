<?php
header("Content-Type: application/json");
require_once "../db.php";
$res = $conn->query("DESCRIBE torneos_americanos");
echo json_encode($res->fetch_all(MYSQLI_ASSOC));
?>
