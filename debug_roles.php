<?php
require_once "db.php";
$sql = "SELECT rol, COUNT(*) as total FROM usuarios GROUP BY rol";
$result = $conn->query($sql);
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}
echo json_encode($data);
?>
