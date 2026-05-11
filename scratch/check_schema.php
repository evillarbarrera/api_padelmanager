<?php
require_once __DIR__ . '/../db/db_config.php';

$tables = ['notificaciones', 'fcm_tokens', 'usuarios'];
foreach ($tables as $table) {
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    if ($res->num_rows > 0) {
        echo "Table $table exists.\n";
        $columns = $conn->query("SHOW COLUMNS FROM $table");
        while ($col = $columns->fetch_assoc()) {
            echo "  - " . $col['Field'] . " (" . $col['Type'] . ")\n";
        }
    } else {
        echo "Table $table does NOT exist.\n";
    }
}

$conn->close();
?>
