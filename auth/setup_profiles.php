<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: text/plain");

require_once "../db.php";

$sql = "
-- 1. Crear la tabla intermedia para manejar múltiples perfiles
CREATE TABLE IF NOT EXISTS usuarios_clubes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    club_id INT NOT NULL,
    rol VARCHAR(50) NOT NULL DEFAULT 'jugador',
    nivel VARCHAR(50) NULL, -- Para guardar el nivel específico en ese club (ej: 3ra, 4ta)
    activo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_user_club (usuario_id, club_id)
);
";

if ($conn->multi_query($sql)) {
    echo "Tabla 'usuarios_clubes' creada correctamente.\n";
    while ($conn->next_result()) {;} // FLush multi_query
} else {
    echo "Error creando tabla: " . $conn->error . "\n";
}

// 2. Migrar los datos actuales (Separado para capturar errores específicos)
// 2. Migrar los datos actuales de forma inteligente
// Verificar qué columnas existen en la tabla usuarios para obtener el club
$colCheck = $conn->query("SHOW COLUMNS FROM usuarios LIKE 'club_id'");
$hasClubId = $colCheck->num_rows > 0;

$colCheck2 = $conn->query("SHOW COLUMNS FROM usuarios LIKE 'administrador_club'");
$hasAdminClub = $colCheck2->num_rows > 0;

$sourceCol = null;
if ($hasClubId) {
    $sourceCol = 'club_id';
} elseif ($hasAdminClub) {
    $sourceCol = 'administrador_club';
}

if ($sourceCol) {
    $sqlMigrate = "
    INSERT INTO usuarios_clubes (usuario_id, club_id, rol)
    SELECT id, $sourceCol, rol 
    FROM usuarios 
    WHERE $sourceCol IS NOT NULL AND $sourceCol > 0
    ON DUPLICATE KEY UPDATE rol = VALUES(rol);
    ";

    if ($conn->query($sqlMigrate)) {
        echo "Migración completada usando la columna '$sourceCol'. Filas afectadas: " . $conn->affected_rows . "\n";
    } else {
        echo "Error en migración: " . $conn->error . "\n";
    }
} else {
    echo "No se encontró columna de club (club_id o administrador_club) en la tabla usuarios. No se migraron datos, pero la tabla usuarios_clubes está lista.\n";
}
?>
