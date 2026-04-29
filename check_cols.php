<?php
require_once "db.php";
$res = $conn->query("DESCRIBE direcciones");
$cols = [];
while($row = $res->fetch_assoc()) {
    $cols[] = $row['Field'];
}
echo json_encode($cols);
?>
