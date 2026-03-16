<?php
$host = "localhost";
$user = "c2632100_manager";
$pass = "boBUraze40";
$dbname = "c2632100_manager";

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$userId = 3;
$sql = "DELETE FROM fcm_tokens WHERE user_id = $userId";

if ($conn->query($sql)) {
    echo "Tokens deleted for user 3. Current count: ";
    $res = $conn->query("SELECT COUNT(*) as c FROM fcm_tokens WHERE user_id = $userId");
    echo $res->fetch_assoc()['c'];
} else {
    echo "Error: " . $conn->error;
}
$conn->close();
?>
