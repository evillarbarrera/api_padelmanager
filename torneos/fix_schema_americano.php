<?php
header("Content-Type: application/json");
require_once "../db.php";

// Silently add creator_id to torneos_americanos if missing
$check = $conn->query("SHOW COLUMNS FROM torneos_americanos LIKE 'creator_id'");
if ($check && $check->num_rows == 0) {
    $conn->query("ALTER TABLE torneos_americanos ADD creator_id INT DEFAULT NULL AFTER club_id");
}

echo json_encode(["success" => true, "message" => "Schema updated"]);
?>
