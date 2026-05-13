<?php
require_once "../db.php";

$res = $conn->query("SELECT id FROM pack_jugadores ORDER BY id DESC LIMIT 100");
$ids = [];
while($row = $res->fetch_assoc()) $ids[] = (int)$row['id'];

echo "Ultimos 100 IDs en pack_jugadores:\n";
print_r($ids);

// Buscar el primer hueco
for($i = $ids[0]; $i > ($ids[0] - 20); $i--) {
    $check = $conn->query("SELECT id FROM pack_jugadores WHERE id = $i");
    if($check->num_rows === 0) {
        echo "POSIBLE HUECO DETECTADO EN ID: $i\n";
    }
}
?>
