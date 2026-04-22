<?php
require_once "db.php";
$new_pass = '1234';
$hashed_pass = password_hash($new_pass, PASSWORD_DEFAULT);
$user_id = 22;

$sql = "UPDATE usuarios SET password = ? WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("si", $hashed_pass, $user_id);

if ($stmt->execute()) {
    echo "Password for user ID $user_id updated successfully to '$new_pass'.\n";
} else {
    echo "Error updating password: " . $conn->error . "\n";
}
?>
