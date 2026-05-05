<?php
require_once "db.php";
$entrenador_id = 9;
$sql = "
    SELECT 
        d.id,
        d.fecha_inicio,
        IF(p.id IS NOT NULL, 'grupal', 'individual') as tipo_real,
        p.id as pack_id,
        p.nombre as pack_nombre
    FROM disponibilidad_profesor d
    LEFT JOIN packs p ON p.entrenador_id = d.profesor_id 
        AND p.tipo = 'grupal' 
        AND p.activo = 1 
        AND p.permite_inscripcion = 1
        AND (p.fecha = DATE(d.fecha_inicio) OR (p.fecha IS NULL AND p.dia_semana = (WEEKDAY(d.fecha_inicio))))
        AND (p.hora_inicio IS NULL OR p.hora_inicio = TIME(d.fecha_inicio))
    WHERE d.profesor_id = ? AND d.activo = 1
      AND DATE(d.fecha_inicio) = '2026-05-02'
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $entrenador_id);
$stmt->execute();
$result = $stmt->get_result();
$data = [];
while($row = $result->fetch_assoc()) { $data[] = $row; }
echo json_encode($data);
?>
