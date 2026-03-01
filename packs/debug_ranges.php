<?php
require_once "../db.php";
$pack_id = $_GET['id'] ?? 25; // Default to 25 as mentioned by user
$sql = "SELECT id, nombre, rango_horario_inicio, rango_horario_fin FROM packs WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $pack_id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();

echo "<h1>Pack Debug</h1>";
echo "<pre>";
print_r($res);
echo "</pre>";
?>
