<?php
// Script to fix durations of existing reservations and blocks for Group Packs
// that have inconsistent duration settings vs actual config.
header("Content-Type: text/plain");
require_once "../db.php";

echo "Iniciando corrección de duraciones para packs grupales...\n";

// 1. Get all group packs with explicit duration set
$sql = "SELECT id, nombre, duracion_sesion_min, hora_inicio FROM packs WHERE tipo='grupal' AND duracion_sesion_min > 0";
$res = $conn->query($sql);

if ($res->num_rows > 0) {
    while ($pack = $res->fetch_assoc()) {
        $duration = $pack['duracion_sesion_min'];
        $packId = $pack['id'];
        $nombre = $pack['nombre'];
        
        echo "Procesando Pack: $nombre (ID: $packId) -> Duración: $duration min\n";
        
        // 1. Update bloques_grupo
        // This affects future generation or availability checks using blocks
        $sql1 = "UPDATE bloques_grupo SET duracion_minutos = ? WHERE pack_id = ?";
        $stmt1 = $conn->prepare($sql1);
        $stmt1->bind_param("ii", $duration, $packId);
        $stmt1->execute();
        $affectedBlocks = $stmt1->affected_rows;
        echo "  Bloques actualizados: $affectedBlocks\n";
        
        // 2. Update active reservations
        // This fixes current calendar view
        // Calculate new end time based on start time + duration
        // We only update reservations LINKED to this pack
        $sql2 = "UPDATE reservas SET hora_fin = ADDTIME(hora_inicio, SEC_TO_TIME(? * 60)) WHERE pack_id = ?";
        $stmt2 = $conn->prepare($sql2);
        $stmt2->bind_param("ii", $duration, $packId);
        $stmt2->execute();
        $affectedReservas = $stmt2->affected_rows;
        echo "  Reservas actualizadas: $affectedReservas\n";
    }
} else {
    echo "No hay packs grupales con duración explícita para corregir.\n";
}

echo "Corrección finalizada.\n";
$conn->close();
?>
