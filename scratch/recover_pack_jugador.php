<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Content-Type: text/html; charset=UTF-8");
require_once "../db.php";

echo "<html><body style='font-family: sans-serif; padding: 20px; line-height: 1.6;'>";
echo "<h2 style='color: #2c3e50;'>Buscador de Packs Eliminados</h2>";
echo "<p>Este script busca reservas que apuntan a un pack de alumno que ya no existe.</p>";

$sql = "SELECT DISTINCT r.pack_jugador_id, r.jugador_id, r.pack_id, u.nombre as jugador_nombre, p.nombre as pack_nombre
        FROM reservas r
        JOIN usuarios u ON u.id = r.jugador_id
        JOIN packs p ON p.id = r.pack_id
        LEFT JOIN pack_jugadores pj ON pj.id = r.pack_jugador_id
        WHERE r.pack_jugador_id > 0 
        AND pj.id IS NULL";

$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background: #f8f9fa;'>
            <th>ID Pack_Jugador Borrado</th>
            <th>Alumno</th>
            <th>Pack Original</th>
            <th>Query de Restauración (Copia y pega en phpMyAdmin)</th>
          </tr>";
          
    while ($row = $result->fetch_assoc()) {
        $id = $row['pack_jugador_id'];
        $jId = $row['jugador_id'];
        $pId = $row['pack_id'];
        
        $restoreQuery = "INSERT INTO pack_jugadores (id, jugador_id, pack_id, fecha_inicio, estado_pago) VALUES ($id, $jId, $pId, NOW(), 'completado');";
        
        echo "<tr>";
        echo "<td><b>$id</b></td>";
        echo "<td>{$row['jugador_nombre']} (ID: $jId)</td>";
        echo "<td>{$row['pack_nombre']} (ID: $pId)</td>";
        echo "<td><code style='background: #eee; padding: 5px; display: block;'>$restoreQuery</code></td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<div style='padding: 20px; background: #e8f4fd; color: #2980b9; border-radius: 5px;'>
            No se encontraron reservas huérfanas. Es posible que el pack borrado no tuviera clases agendadas aún.
          </div>";
}

echo "</body></html>";
?>
