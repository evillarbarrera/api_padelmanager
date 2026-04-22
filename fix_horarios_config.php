<?php
require_once "db.php";

echo "--- INICIO DE REPARACIÓN DE HORARIOS ---\n";

// 1. Crear tabla si no existe
$sqlCreateTable = "CREATE TABLE IF NOT EXISTS cancha_horarios_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cancha_id INT NOT NULL,
    dia_semana TINYINT NOT NULL COMMENT '0=Dom, 1=Lun, ..., 6=Sab',
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    duracion_bloque INT DEFAULT 90 COMMENT 'minutos',
    INDEX (cancha_id),
    INDEX (dia_semana)
)";

if ($conn->query($sqlCreateTable)) {
    echo "Tabla cancha_horarios_config verificada/creada.\n";
} else {
    echo "Error creando tabla: " . $conn->error . "\n";
}

// 2. Verificar si hay datos
$res = $conn->query("SELECT id FROM cancha_horarios_config LIMIT 1");
if ($res->num_rows == 0) {
    echo "No se encontraron horarios. Insertando datos de prueba para todas las canchas...\n";
    
    // Obtener todas las canchas
    $resCanchas = $conn->query("SELECT id FROM canchas");
    if ($resCanchas) {
        $stmt = $conn->prepare("INSERT INTO cancha_horarios_config (cancha_id, dia_semana, hora_inicio, hora_fin, duracion_bloque) VALUES (?, ?, ?, ?, ?)");
        
        while ($cancha = $resCanchas->fetch_assoc()) {
            $cid = $cancha['id'];
            // Insertar para cada día de la semana (0-6)
            for ($d = 0; $d <= 6; $d++) {
                // Bloque de la mañana: 06:00 a 23:30 con bloques de 90 min
                $h_ini = "06:00:00";
                $h_fin = "23:00:00";
                $dur = 90;
                $stmt->bind_param("iissi", $cid, $d, $h_ini, $h_fin, $dur);
                $stmt->execute();
            }
        }
        echo "Datos de prueba insertados con éxito.\n";
    }
} else {
    echo "La tabla ya tiene datos de configuración.\n";
}

echo "--- FIN ---\n";
?>
