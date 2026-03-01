<?php
header("Content-Type: application/json");
require_once "../db.php";

$queries = [
    // 1. Añadir creador_id a torneos_v2 para que siempre aparezcan a quien los creó
    "ALTER TABLE torneos_v2 ADD COLUMN IF NOT EXISTS creator_id INT AFTER club_id",
    
    // 2. Modificar torneo_parejas para soportar no usuarios (nombres manuales)
    "ALTER TABLE torneo_parejas MODIFY jugador1_id INT NULL",
    "ALTER TABLE torneo_parejas ADD COLUMN IF NOT EXISTS jugador1_nombre_manual VARCHAR(100) AFTER jugador1_id",
    "ALTER TABLE torneo_parejas ADD COLUMN IF NOT EXISTS jugador2_nombre_manual VARCHAR(100) AFTER jugador2_id",
    
    // 3. Asegurar que torneos_v2 tenga la columna estado si no existe
    "ALTER TABLE torneos_v2 MODIFY COLUMN estado ENUM('Inscripción', 'En Curso', 'Finalizado') DEFAULT 'Inscripción'"
];

$results = [];
foreach ($queries as $sql) {
    if ($conn->query($sql)) {
        $results[] = ["status" => "success", "query" => substr($sql, 0, 50)];
    } else {
        $results[] = ["status" => "error", "message" => $conn->error];
    }
}

echo json_encode(["migration_results" => $results]);
?>
