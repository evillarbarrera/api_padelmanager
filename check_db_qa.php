<?php
require_once "db.php";
header("Content-Type: application/json");

$result = $conn->query("SHOW COLUMNS FROM usuarios");
$columns = [];
while($row = $result->fetch_assoc()) {
    $columns[] = $row['Field'];
}

echo json_encode([
    "status" => "success",
    "columns" => $columns,
    "has_paypal_col" => in_array('paypal_sub_id', $columns),
    "has_mp_col" => in_array('mp_preapproval_id', $columns)
]);
