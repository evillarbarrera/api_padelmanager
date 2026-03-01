<?php
header("Content-Type: application/json");
require_once "db.php";

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
$basePath = dirname($scriptDir);
if ($basePath === DIRECTORY_SEPARATOR || $basePath === '\\') $basePath = '';

$correctBaseUrl = $protocol . "://" . $host . $basePath . "/uploads/videos/";

$result = $conn->query("SELECT id, video_url FROM entrenamiento_videos");
$updated = 0;

while ($row = $result->fetch_assoc()) {
    $id = $row['id'];
    $oldUrl = $row['video_url'];
    
    // Extract filename
    $parts = explode('/', $oldUrl);
    $filename = end($parts);
    
    $newUrl = $correctBaseUrl . $filename;
    
    if ($oldUrl !== $newUrl) {
        $stmt = $conn->prepare("UPDATE entrenamiento_videos SET video_url = ? WHERE id = ?");
        $stmt->bind_param("si", $newUrl, $id);
        $stmt->execute();
        $updated++;
    }
}

echo json_encode([
    "success" => true,
    "updated_count" => $updated,
    "new_base_url" => $correctBaseUrl
]);
?>
