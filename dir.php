<?php
echo "Current directory: " . getcwd() . "<br>";
echo "Contents of notifications/:<br>";
print_r(scandir('notifications'));
