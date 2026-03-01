<?php
require_once "db.php";
$sql = "ALTER TABLE reservas_cancha 
        ADD COLUMN jugador2_id INT NULL AFTER usuario_id,
        ADD COLUMN jugador3_id INT NULL AFTER jugador2_id,
        ADD COLUMN jugador4_id INT NULL AFTER jugador3_id,
        ADD COLUMN nombre_externo2 VARCHAR(255) NULL AFTER nombre_externo,
        ADD COLUMN nombre_externo3 VARCHAR(255) NULL AFTER nombre_externo2,
        ADD COLUMN nombre_externo4 VARCHAR(255) NULL AFTER nombre_externo3,
        MODIFY COLUMN usuario_id INT NULL";

if ($conn->query($sql)) {
    echo json_encode(["success" => true, "message" => "DB Updated"]);
} else {
    echo json_encode(["success" => false, "error" => $conn->error]);
}
?>
