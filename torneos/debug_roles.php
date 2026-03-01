<?php
require_once "../db.php";
$res = $conn->query("SELECT DISTINCT role FROM usuarios");
$roles = [];
while($row = $res->fetch_assoc()) $roles[] = $row['role'];
echo json_encode($roles);
?>
