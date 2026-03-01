<?php
header("Content-Type: text/plain");
$file = 'debug_insert_pack.log';
if (file_exists($file)) {
    echo file_get_contents($file);
} else {
    echo "No log file found. Try making a purchase request first.";
}
?>
