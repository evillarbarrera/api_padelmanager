<?php
/**
 * Setup Logros (Achievements) System
 * Run ONCE to create tables and seed badge definitions.
 */
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require_once "../db.php";

// 1. Create logros catalog table
$conn->query("
CREATE TABLE IF NOT EXISTS logros (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(50) UNIQUE NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    descripcion VARCHAR(255) NOT NULL,
    icono VARCHAR(10) NOT NULL,
    categoria ENUM('constancia', 'progreso', 'ia') NOT NULL,
    requisito_valor INT DEFAULT 1,
    orden INT DEFAULT 0,
    color_badge VARCHAR(7) DEFAULT '#CCFF00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// 2. Create player achievements table
$conn->query("
CREATE TABLE IF NOT EXISTS jugador_logros (
    id INT AUTO_INCREMENT PRIMARY KEY,
    jugador_id INT NOT NULL,
    logro_id INT NOT NULL,
    desbloqueado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notificado BOOLEAN DEFAULT FALSE,
    UNIQUE KEY unique_jugador_logro (jugador_id, logro_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// 3. Seed badge definitions (INSERT IGNORE to be idempotent)
$badges = [
    // Constancia (5)
    ['primera_clase',      'Primera Clase',        'Completá tu primera clase de pádel',                        '🎾', 'constancia', 1,  1, '#CCFF00'],
    ['racha_fuego',        'Racha de Fuego',       '4 clases consecutivas sin faltar',                          '🔥', 'constancia', 4,  2, '#FF6B00'],
    ['maquina_imparable',  'Máquina Imparable',    'Completá 10 clases de entrenamiento',                       '⚡', 'constancia', 10, 3, '#FFD700'],
    ['centurion',          'Centurión',            'Completá 50 clases de entrenamiento',                       '💯', 'constancia', 50, 4, '#E5E4E2'],
    ['madrugador',         'Madrugador',           'Asistí a 5 clases antes de las 10:00',                      '🌅', 'constancia', 5,  5, '#87CEEB'],

    // Progreso (4)
    ['primera_evaluacion', 'Primera Evaluación',   'Recibí tu primera evaluación del entrenador',                '📊', 'progreso',   1,  6, '#3B82F6'],
    ['nivel_up',           'Nivel Up',             'Subí tu promedio general entre dos evaluaciones',             '📈', 'progreso',   1,  7, '#10B981'],
    ['golpe_perfecto',     'Golpe Perfecto',       'Obtené un score ≥ 9 en algún golpe',                         '⭐', 'progreso',   9,  8, '#F59E0B'],
    ['evolucion_total',    'Evolución Total',      '3 evaluaciones con tendencia ascendente',                    '🚀', 'progreso',   3,  9, '#8B5CF6'],

    // IA (3)
    ['ojo_ia',             'Ojo de IA',            'Analizá tu primer video con Inteligencia Artificial',        '🤖', 'ia',         1,  10, '#06B6D4'],
    ['golpe_maestro',      'Golpe Maestro',        'Obtené un score ≥ 85 en análisis de video con IA',           '🎯', 'ia',         85, 11, '#EF4444'],
    ['analista_pro',       'Analista Pro',         'Analizá 5 o más videos con IA',                              '🔬', 'ia',         5,  12, '#A855F7'],
];

$stmt = $conn->prepare("
    INSERT IGNORE INTO logros (codigo, nombre, descripcion, icono, categoria, requisito_valor, orden, color_badge) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");

$inserted = 0;
foreach ($badges as $b) {
    $stmt->bind_param("sssssijs", $b[0], $b[1], $b[2], $b[3], $b[4], $b[5], $b[6], $b[7]);
    $stmt->execute();
    if ($conn->affected_rows > 0) $inserted++;
}

echo json_encode([
    "success" => true,
    "message" => "Logros system initialized",
    "badges_inserted" => $inserted,
    "total_badges" => count($badges)
]);
?>
