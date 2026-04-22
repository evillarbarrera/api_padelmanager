<?php
require_once "db.php";
$sql = "DESCRIBE cupones";
$result = $conn->query($sql);
$schema = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $schema[] = $row;
    }
    echo json_encode(["success" => true, "schema" => $schema]);
} else {
    echo json_encode(["success" => false, "error" => $conn->error]);
}
?>
