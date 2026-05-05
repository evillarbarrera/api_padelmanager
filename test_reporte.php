<?php
require_once "db.php";

$club_id = 1; // You can change this
echo "=== PRUEBA DE REPORTES ===\n";

$baseSql = "
    SELECT DATE(fecha) as fecha_v, total as monto
    FROM inventario_ventas 
    WHERE club_id = $club_id
    UNION ALL
    SELECT r.fecha as fecha_v, r.precio as monto
    FROM reservas_cancha r
    JOIN canchas c ON r.cancha_id = c.id
    WHERE c.club_id = $club_id AND r.pagado = 1 AND r.estado != 'Cancelada'
";

$result = $conn->query($baseSql);
if ($result) {
    echo "Registros encontrados: " . $result->num_rows . "\n";
    $total = 0;
    while($row = $result->fetch_assoc()){
        $total += (float)$row['monto'];
    }
    echo "Total acumulado histórico: $total \n";
} else {
    echo "Error en consulta: " . $conn->error . "\n";
}
?>
