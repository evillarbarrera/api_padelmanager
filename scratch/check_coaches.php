<?php
require_once __DIR__ . '/../db/db_config.php';

$sql = "SELECT id, nombre, rol, created_at, onboarding_completed FROM usuarios WHERE rol = 'entrenador' ORDER BY created_at DESC LIMIT 10";
$result = $conn->query($sql);

echo "Coaches found:\n";
while ($row = $result->fetch_assoc()) {
    print_r($row);
}

$conn->close();
?>
