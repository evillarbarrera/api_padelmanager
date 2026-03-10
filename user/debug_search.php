<?php
// Debug wrapper for get_users.php
$search = $_GET['search'] ?? 'no search';
$rol = $_GET['rol'] ?? 'no rol';
$log = date('Y-m-d H:i:s') . " - Search: $search, Rol: $rol\n";
file_put_contents('search_debug.log', $log, FILE_APPEND);

require_once "get_users.php";
?>
