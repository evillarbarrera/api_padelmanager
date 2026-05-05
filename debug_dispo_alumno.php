<?php
require_once "db.php";
$entrenador_id = 9;
$sql = "
    SELECT 
        r.id as id,
        CONCAT(r.fecha, ' ', r.hora_inicio) as fecha_inicio,
        r.tipo as tipo_real,
        r.estado,
        (SELECT COUNT(*) FROM reserva_jugadores rj2 WHERE rj2.reserva_id = r.id) as inscritos_count
    FROM reservas r
    WHERE r.entrenador_id = ? 
      AND r.estado = 'reservado' 
      AND r.fecha = '2026-05-02'
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $entrenador_id);
$stmt->execute();
$result = $stmt->get_result();
$data = [];
while($row = $result->fetch_assoc()) { $data[] = $row; }
echo json_encode($data);
?>
