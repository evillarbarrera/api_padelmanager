<?php
/**
 * Padelblox - Ejecutor Seguro de Índices de Base de Datos
 * Puede ejecutarse desde el navegador web o vía línea de comandos (CLI).
 */
header("Content-Type: text/plain; charset=UTF-8");
require_once __DIR__ . "/../db.php";

echo "=========================================================\n";
echo "Padelblox - Verificación y Creación de Índices de Velocidad\n";
echo "=========================================================\n\n";

function ensureIndex($conn, $table, $indexName, $columnsSql) {
    // 1. Verificar si la tabla existe
    $checkT = $conn->query("SHOW TABLES LIKE '$table'");
    if (!$checkT || $checkT->num_rows === 0) {
        echo "[!] La tabla '$table' no existe. Omitiendo índice '$indexName'.\n";
        return;
    }

    // 2. Verificar si el índice ya existe
    $checkI = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '$indexName'");
    if ($checkI && $checkI->num_rows > 0) {
        echo "[✓] El índice '$indexName' YA ESTÁ CREADO en la tabla '$table'.\n";
        return;
    }

    // 3. Crear el índice
    $sql = "CREATE INDEX `$indexName` ON `$table` ($columnsSql)";
    echo "[...] Creando índice '$indexName' en '$table' ($columnsSql)... ";
    if ($conn->query($sql)) {
        echo "¡ÉXITO!\n";
    } else {
        echo "ERROR: " . $conn->error . "\n";
    }
}

// 1. Índice para disponibilidad de reservas
ensureIndex($conn, 'reservas_cancha', 'idx_reservas_disponibilidad', 'fecha, cancha_id, estado, hora_inicio, hora_fin');

// 2. Índice para canchas activas por club
ensureIndex($conn, 'canchas', 'idx_canchas_club_activa', 'club_id, activa, id');

// 3. Índice para configuración horaria por cancha y día
ensureIndex($conn, 'cancha_horarios_config', 'idx_cancha_horarios_dia', 'cancha_id, dia_semana');

// 4. Índice para torneos americanos activos
ensureIndex($conn, 'torneos_americanos', 'idx_torneos_americanos_fecha', 'fecha, estado, club_id');

// 5. Índice para participantes de torneos americanos
ensureIndex($conn, 'torneo_participantes', 'idx_torneo_part_torneo', 'torneo_id, jugador_id, jugador2_id');

// 6. Índice para categorías de torneos tradicionales V2
ensureIndex($conn, 'torneo_categorias', 'idx_torneo_categorias_torneo', 'torneo_id');

// 7. Índice para inscripciones de torneos tradicionales V2
ensureIndex($conn, 'torneo_inscripciones', 'idx_torneo_inscrip_cat', 'categoria_id, pareja_id');

echo "\nProceso finalizado.\n";
