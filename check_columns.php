<?php
require_once "db.php";
$result = $conn->query("DESCRIBE reservas");
$columns = [];
while($row = $result->fetch_assoc()) {
    $columns[] = $row['Field'];
}
echo json_encode($columns);
?>
