<?php
// Script to fix data integrity for Packs:
// 1. Recalculate cupos_ocupados based on actual active inscriptions.
// 2. Fix durations in blocks and reservations based on pack config.

header("Content-Type: text/plain");
require_once "../db.php";

echo "Iniciando reparación de integridad de datos...\n\n";

// --- PART 1: Sync Cupos Ocupados ---
echo "1. Sincronizando cupos_ocupados...\n";
$sql_sync = "
    UPDATE packs p
    SET cupos_ocupados = (
        SELECT COUNT(*)
        FROM inscripciones_grupales ig
        WHERE ig.pack_id = p.id AND ig.estado = 'activo'
    )
    WHERE p.tipo = 'grupal'
";
if ($conn->query($sql_sync)) {
    echo "   Cupos sincronizados: " . $conn->affected_rows . " packs actualizados.\n";
} else {
    echo "   Error al sincronizar cupos: " . $conn->error . "\n";
}

// --- PART 2: Fix Durations ---
echo "\n2. Corrigiendo duraciones de bloques y reservas...\n";
$sql_packs = "SELECT id, nombre, duracion_sesion_min FROM packs WHERE tipo='grupal' AND duracion_sesion_min > 0";
$res = $conn->query($sql_packs);

if ($res->num_rows > 0) {
    while ($pack = $res->fetch_assoc()) {
        $duration = $pack['duracion_sesion_min'];
        $packId = $pack['id'];
        $nombre = $pack['nombre'];
        
        echo "   Procesando Pack: $nombre (ID: $packId) -> Ajustando a $duration min\n";
        
        // Update bloques_grupo
        $sql1 = "UPDATE bloques_grupo SET duracion_minutos = ? WHERE pack_id = ?";
        $stmt1 = $conn->prepare($sql1);
        $stmt1->bind_param("ii", $duration, $packId);
        $stmt1->execute();
        
        // Update active reservations (recalculate end time)
        // Only for future reservations or all? All is safer to keep history consistent.
        $sql2 = "UPDATE reservas SET hora_fin = ADDTIME(hora_inicio, SEC_TO_TIME(? * 60)) WHERE pack_id = ?";
        $stmt2 = $conn->prepare($sql2);
        $stmt2->bind_param("ii", $duration, $packId);
        $stmt2->execute();
    }
} else {
    echo "   No hay packs con duración explícita para corregir.\n";
}

echo "\nProceso finalizado.\n";
$conn->close();
?>
