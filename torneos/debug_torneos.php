<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: text/plain");

echo "Step 1: PHP is working.\n";

if (!file_exists("../db.php")) {
    die("Error: ../db.php does not exist.");
}
echo "Step 2: Found db.php.\n";

require_once "../db.php";

if (!isset($conn)) {
    die("Error: \$conn variable not set in db.php.");
}
if ($conn->connect_error) {
    die("Error: Connection failed: " . $conn->connect_error);
}
echo "Step 3: Database connected successfully.\n";

$torneo_id = 10;
echo "Step 4: Querying participants for torneo $torneo_id...\n";

$sql = "SELECT id, nombre_pareja FROM torneo_participantes WHERE torneo_id = $torneo_id LIMIT 5";
$res = $conn->query($sql);

if ($res) {
    echo "Step 5: Query OK. Found " . $res->num_rows . " participants.\n";
    while ($row = $res->fetch_assoc()) {
        echo "- " . $row['nombre_pareja'] . " (ID: " . $row['id'] . ")\n";
    }
} else {
    echo "Error: Query failed: " . $conn->error . "\n";
}
?>

