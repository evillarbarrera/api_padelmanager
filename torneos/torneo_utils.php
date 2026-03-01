<?php

function insertMatch($conn, $torneo_id, $r, $num_cancha, $hora_ini, $pair1, $pair2, $grupo_id, $fase, $cancha_db_id, $fecha, $hora_fin) {
    $sqlInsert = "INSERT INTO torneo_partidos (
        torneo_id, ronda, num_cancha, hora_inicio,
        jugador1_id, jugador2_id, jugador3_id, jugador4_id,
        nombre_externo_1, nombre_externo_2, nombre_externo_3, nombre_externo_4,
        grupo_id, fase
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmtI = $conn->prepare($sqlInsert);
    if (!$stmtI) return false;

    $p1 = $pair1['jugador_id'] ?: null; $p2 = $pair1['jugador2_id'] ?: null;
    $p3 = $pair2['jugador_id'] ?: null; $p4 = $pair2['jugador2_id'] ?: null;
    $n1 = $pair1['nombre_externo_1'] ?: null; $n2 = $pair1['nombre_externo_2'] ?: null;
    $n3 = $pair2['nombre_externo_1'] ?: null; $n4 = $pair2['nombre_externo_2'] ?: null;

    $stmtI->bind_param("iiisiiiissssss", 
        $torneo_id, $r, $num_cancha, $hora_ini,
        $p1, $p2, $p3, $p4, $n1, $n2, $n3, $n4,
        $grupo_id, $fase
    );
    $stmtI->execute();
    $partido_id = $conn->insert_id;

    if ($cancha_db_id) {
        $sqlReserva = "INSERT INTO reservas_cancha 
                      (cancha_id, usuario_id, jugador2_id, jugador3_id, jugador4_id, 
                       nombre_externo, nombre_externo2, nombre_externo3, nombre_externo4, 
                       fecha, hora_inicio, hora_fin, precio, estado) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmtR = $conn->prepare($sqlReserva);
        $precio = 0; $estado = 'Confirmada';
        $stmtR->bind_param("iiiiisssssssds", 
            $cancha_db_id, $p1, $p2, $p3, $p4, $n1, $n2, $n3, $n4,
            $fecha, $hora_ini, $hora_fin, $precio, $estado
        );
        $stmtR->execute();
    }
    return $partido_id;
}
?>
