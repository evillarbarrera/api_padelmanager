<?php
require_once "../db.php";

$sql1 = "ALTER TABLE clubes ADD COLUMN region VARCHAR(255) DEFAULT ''";
$sql2 = "ALTER TABLE clubes ADD COLUMN comuna VARCHAR(255) DEFAULT ''";

if ($conn->query($sql1)) {
    echo "Column 'region' added.<br>";
} else {
    echo "Error adding 'region': " . $conn->error . "<br>";
}

if ($conn->query($sql2)) {
    echo "Column 'comuna' added.<br>";
} else {
    echo "Error adding 'comuna': " . $conn->error . "<br>";
}
?>
