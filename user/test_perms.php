<?php
$dir = '../uploads/perfiles/';
if (!is_dir($dir)) {
    if (mkdir($dir, 0777, true)) {
        echo "Directory created successfully: $dir\n";
    } else {
        echo "Failed to create directory: $dir\n";
    }
} else {
    echo "Directory already exists: $dir\n";
}

if (is_writable($dir)) {
    echo "Directory is writable!\n";
} else {
    echo "Directory is NOT writable. Checking permissions...\n";
    echo "Current permissions: " . substr(sprintf('%o', fileperms($dir)), -4) . "\n";
    echo "Current user: " . get_current_user() . "\n";
}
?>
