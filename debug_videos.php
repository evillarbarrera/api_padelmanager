<?php
header("Content-Type: application/json");
require_once "db.php";

$uploadDir = "uploads/videos/";
$files = [];

if (file_exists($uploadDir)) {
    foreach (scandir($uploadDir) as $file) {
        if ($file !== '.' && $file !== '..') {
            $path = $uploadDir . $file;
            $files[] = [
                "name" => $file,
                "size" => filesize($path),
                "is_readable" => is_readable($path),
                "mtime" => date("Y-m-d H:i:s", filemtime($path))
            ];
        }
    }
} else {
    $error = "Directory $uploadDir does not exist";
}

$db_records = [];
try {
    $result = $conn->query("SELECT * FROM entrenamiento_videos ORDER BY id DESC LIMIT 10");
    while($row = $result->fetch_assoc()) {
        $db_records[] = $row;
    }
} catch (Exception $e) {
    $db_error = $e->getMessage();
}

echo json_encode([
    "directory" => $uploadDir,
    "exists" => file_exists($uploadDir),
    "files" => $files,
    "db_records" => $db_records,
    "error" => $error ?? null,
    "db_error" => $db_error ?? null
]);
?>
