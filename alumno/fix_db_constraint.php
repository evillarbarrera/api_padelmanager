<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Content-Type: application/json");

require_once "../db.php";

$table = 'pack_jugadores';
$dropped = [];
$errors = [];

// 1. Find the index name
$sql = "SHOW INDEX FROM $table";
$result = $conn->query($sql);

$indexes = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $keyName = $row['Key_name'];
        if (!isset($indexes[$keyName])) {
            $indexes[$keyName] = [
                'unique' => ($row['Non_unique'] == 0),
                'columns' => []
            ];
        }
        $indexes[$keyName]['columns'][] = $row['Column_name'];
    }
} else {
    die(json_encode(["error" => "Failed to list indexes: " . $conn->error]));
}

// 2. Identify the target unique index
foreach ($indexes as $name => $info) {
    // We are looking for a UNIQUE index that contains 'pack_id' and 'jugador_id'
    // It might be 'pack_id' (if composite) or 'unique_pack_player' etc.
    // We strictly want to avoid dropping PRIMARY
    if ($name === 'PRIMARY') continue;

    if ($info['unique']) {
        $cols = $info['columns'];
        // Check if it involves our conflict columns
        if (in_array('pack_id', $cols) && in_array('jugador_id', $cols)) {
            // This is likely the culprit
            $dropSql = "ALTER TABLE $table DROP INDEX `$name`";
            if ($conn->query($dropSql)) {
                $dropped[] = $name;
            } else {
                $errors[] = "Failed to drop $name: " . $conn->error;
            }
        }
    }
}

echo json_encode([
    "status" => "finished",
    "dropped_indexes" => $dropped,
    "errors" => $errors,
    "scanned_indexes" => array_keys($indexes)
], JSON_PRETTY_PRINT);
?>
