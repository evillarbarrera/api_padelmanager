<?php
require_once "db.php";
$res = $conn->query("SHOW TABLES");
$data = [];
while($row = $res->fetch_array()){
    $data[] = $row[0];
}
echo json_encode($data, JSON_PRETTY_PRINT);
?>
