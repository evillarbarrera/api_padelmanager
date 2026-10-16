<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

$result = $conn->query("SHOW COLUMNS FROM clubes LIKE 'logo'");

if ($result && $result->num_rows == 0) {
    if ($conn->query("ALTER TABLE clubes ADD COLUMN logo VARCHAR(255) NULL AFTER email")) {
        echo json_encode(["success" => true, "message" => "Column 'logo' added successfully."]);
    } else {
        http_response_code(500);
        echo json_encode(["success" => false, "error" => $conn->error]);
    }
} else {
    echo json_encode(["success" => true, "message" => "Column 'logo' already exists."]);
}
?>
