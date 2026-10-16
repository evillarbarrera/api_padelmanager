<?php
require_once "db.php";

$sql = "ALTER TABLE usuarios ADD COLUMN mp_collector_id VARCHAR(100) DEFAULT NULL";

if ($conn->query($sql)) {
    echo "Column mp_collector_id added successfully.";
} else {
    echo "Error: " . $conn->error;
}
