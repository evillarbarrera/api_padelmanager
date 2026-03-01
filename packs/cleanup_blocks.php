<?php
header("Content-Type: text/plain");
require_once "../db.php";

echo "Iniciando limpieza de bloques de grupo huérfanos o desactualizados...\n";

// 1. Eliminar bloques donde el pack ya no existe o no está activo
$sql1 = "DELETE bg FROM bloques_grupo bg
         LEFT JOIN packs p ON p.id = bg.pack_id
         WHERE p.id IS NULL OR p.activo = 0";
$conn->query($sql1);
echo "Bloques de packs inexistentes o inactivos eliminados: " . $conn->affected_rows . "\n";

// 2. Eliminar bloques donde el horario o día del pack ha cambiado
$sql2 = "DELETE bg FROM bloques_grupo bg
         JOIN packs p ON p.id = bg.pack_id
         WHERE bg.hora_inicio != p.hora_inicio OR bg.dia_semana != p.dia_semana";
$conn->query($sql2);
echo "Bloques con horario actualizado eliminados (se regenerarán al inscribir): " . $conn->affected_rows . "\n";

echo "Limpieza finalizada.\n";
$conn->close();
?>
