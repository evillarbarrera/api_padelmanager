<?php
require_once "db.php";
$entrenador_id = 9;
$fecha = '2026-05-01';
$sql = "SELECT id, fecha, hora_inicio, tipo, estado, pack_id FROM reservas WHERE entrenador_id = ? AND fecha = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $entrenador_id, $fecha);
$stmt->execute();
$result = $stmt->get_result();
$data = [];
while($row = $result->fetch_assoc()) {
    $data[] = $row;
}
echo json_encode($data);
?>
