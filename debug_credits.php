<?php
require_once "db.php";
header("Content-Type: text/plain");

$jugador_id = 3;

echo "--- DATOS PARA USUARIO $jugador_id ---\n\n";

// Packs comprados
echo "PACKS COMPRADOS (pack_jugadores):\n";
$sql = "SELECT pj.*, p.nombre, p.entrenador_id, p.sesiones_totales, p.tipo 
        FROM pack_jugadores pj 
        JOIN packs p ON p.id = pj.pack_id 
        WHERE pj.jugador_id = $jugador_id";
$res = $conn->query($sql);
while($row = $res->fetch_assoc()) {
    echo "ID: {$row['id']} | Pack: {$row['nombre']} | CoachID: {$row['entrenador_id']} | Totales: {$row['sesiones_totales']} | Tipo: {$row['tipo']}\n";
}

echo "\nRESERVAS ACTIVAS (no canceladas):\n";
$sql = "SELECT r.id, r.fecha, r.hora_inicio, r.estado, r.tipo, r.entrenador_id, r.pack_id, u.nombre as entrenador_nombre
        FROM reservas r
        JOIN reserva_jugadores rj ON rj.reserva_id = r.id
        JOIN usuarios u ON u.id = r.entrenador_id
        WHERE rj.jugador_id = $jugador_id AND r.estado != 'cancelado'
        ORDER BY r.fecha DESC, r.hora_inicio DESC";
$res = $conn->query($sql);
while($row = $res->fetch_assoc()) {
    echo "ID: {$row['id']} | Fecha: {$row['fecha']} {$row['hora_inicio']} | Estado: {$row['estado']} | Coach: {$row['entrenador_nombre']} (ID: {$row['entrenador_id']}) | PackID: {$row['pack_id']} | Tipo: {$row['tipo']}\n";
}

// Cálculo estilo reservas.php
echo "\n--- CALCULO ESTILO RESERVAS.PHP (POR ENTRENADOR) ---\n";
$sql = "SELECT p.entrenador_id, u.nombre as coach_name, SUM(p.sesiones_totales) as totales
        FROM pack_jugadores pj
        JOIN packs p ON p.id = pj.pack_id
        JOIN usuarios u ON u.id = p.entrenador_id
        WHERE pj.jugador_id = $jugador_id
        GROUP BY p.entrenador_id";
$res = $conn->query($sql);
while($coach = $res->fetch_assoc()) {
    $eId = $coach['entrenador_id'];
    $tot = $coach['totales'];
    
    $sqlUsadas = "SELECT COUNT(DISTINCT r.id) as usadas
                  FROM reservas r
                  JOIN reserva_jugadores rj ON rj.reserva_id = r.id
                  WHERE rj.jugador_id = $jugador_id 
                    AND r.entrenador_id = $eId
                    AND r.estado != 'cancelado'
                    AND r.tipo NOT IN ('grupal', 'pack_grupal')";
    $resUsadas = $conn->query($sqlUsadas)->fetch_assoc();
    $used = $resUsadas['usadas'];
    $disp = $tot - $used;
    
    echo "Coach: {$coach['coach_name']} | Totales: $tot | Usadas: $used | Disponibles: $disp\n";
}
?>
