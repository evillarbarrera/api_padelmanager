<?php
// match_utils.php

/**
 * Función auxiliar para insertar partidos y reservas
 */
function insertMatch($conn, $torneo_id, $r, $num_cancha, $hora_ini, $pair1, $pair2, $grupo_id, $fase, $cancha_db_id, $fecha, $hora_fin) {
    if (!$conn) return 0;
    
    $sqlInsert = "INSERT INTO torneo_partidos (
        torneo_id, ronda, num_cancha, hora_inicio,
        jugador1_id, jugador2_id, jugador3_id, jugador4_id,
        nombre_externo_1, nombre_externo_2, nombre_externo_3, nombre_externo_4,
        grupo_id, fase
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmtI = $conn->prepare($sqlInsert);
    if (!$stmtI) return 0;

    $p1 = $pair1['jugador_id'] ?? null; 
    $p2 = $pair1['jugador2_id'] ?? null;
    $p3 = $pair2['jugador_id'] ?? null; 
    $p4 = $pair2['jugador2_id'] ?? null;
    $n1 = $pair1['nombre_externo_1'] ?? null; 
    $n2 = $pair1['nombre_externo_2'] ?? null;
    $n3 = $pair2['nombre_externo_1'] ?? null; 
    $n4 = $pair2['nombre_externo_2'] ?? null;

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
                       fecha, hora_inicio, hora_fin, precio, estado, torneo_id) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmtR = $conn->prepare($sqlReserva);
        if ($stmtR) {
            $precio = 0; $estado = 'Confirmada';
            // Use 0 or NULL for player IDs if empty
            $r_p1 = $p1 ?: null; $r_p2 = $p2 ?: null; $r_p3 = $p3 ?: null; $r_p4 = $p4 ?: null;
            
            $stmtR->bind_param("iiiiisssssssdsi", 
                $cancha_db_id, $r_p1, $r_p2, $r_p3, $r_p4, $n1, $n2, $n3, $n4,
                $fecha, $hora_ini, $hora_fin, $precio, $estado, $torneo_id
            );
            $stmtR->execute();
        }
    }
    return $partido_id;
}
