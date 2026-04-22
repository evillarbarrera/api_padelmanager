<?php
require_once "db.php";
header("Content-Type: text/plain");

echo "AUDITORIA DE MALLA PARA JUGADOR ID=3\n";
echo "====================================\n\n";

// 1. Check Segumiento
$sql1 = "SELECT id, malla_id, entrenador_id, estado FROM alumno_malla_seguimiento WHERE jugador_id = 3";
$res1 = $conn->query($sql1);
if ($res1->num_rows > 0) {
    echo "1. SEGUIMIENTO ENCONTRADO:\n";
    while($row = $res1->fetch_assoc()) {
        print_r($row);
        $mid = $row['malla_id'];
        
        // 2. Check Malla
        $sql2 = "SELECT id, nombre, nivel FROM mallas WHERE id = $mid";
        $res2 = $conn->query($sql2);
        if ($res2->num_rows > 0) {
            echo "\n2. MALLA ENCONTRADA (ID: $mid):\n";
            print_r($res2->fetch_assoc());
            
            // 3. Check Classes
            $sql3 = "SELECT id, titulo, orden FROM clases_malla WHERE malla_id = $mid ORDER BY orden ASC";
            $res3 = $conn->query($sql3);
            if ($res3->num_rows > 0) {
                echo "\n3. CLASES ENCONTRADAS (" . $res3->num_rows . "):\n";
                while($c = $res3->fetch_assoc()) {
                    echo "   - Clase " . $c['orden'] . ": " . $c['titulo'] . " (ID: " . $c['id'] . ")\n";
                }
            } else {
                echo "\n3. ERROR: NO HAY CLASES EN 'clases_malla' PARA ESTA MALLA ID: $mid\n";
            }
        } else {
            echo "\n2. ERROR: NO EXISTE LA MALLA CON ID: $mid EN LA TABLA 'mallas'\n";
        }
    }
} else {
    echo "1. ERROR: NO HAY REGISTROS EN 'alumno_malla_seguimiento' PARA JUGADOR ID=3\n";
}

// 4. Check Attendance for context
$sql4 = "SELECT id, clase_malla_id FROM alumno_asistencia WHERE alumno_malla_id IN (SELECT id FROM alumno_malla_seguimiento WHERE jugador_id = 3)";
$res4 = $conn->query($sql4);
echo "\n4. ASISTENCIAS REGISTRADAS: " . $res4->num_rows . "\n";

$conn->close();
?>
