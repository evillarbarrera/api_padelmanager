<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
require_once "../db.php";
$id = $_GET['entrenador_id'] ?? 0;
$sql = "SELECT * FROM disponibilidad_profesor WHERE profesor_id = ? AND activo = 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
echo json_encode($data);
?>
