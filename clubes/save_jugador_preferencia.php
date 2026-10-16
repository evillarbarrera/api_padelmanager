<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type, X-Requested-With, Accept, Origin");
header("Access-Control-Max-Age: 86400");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

ob_start();
error_reporting(0);
ini_set('display_errors', 0);

try {
    require_once "../db.php";
    require_once "../auth/auth_helper.php";

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $jugadorId = isset($input['usuario_id']) ? (int)$input['usuario_id'] : 0;
    $clubId = isset($input['club_id']) ? (int)$input['club_id'] : 0;
    $posicion = isset($input['posicion']) ? trim($input['posicion']) : 'Ambos';
    $categoria = isset($input['categoria']) ? trim($input['categoria']) : '4ª';
    $brazoHabil = isset($input['brazo_habil']) ? trim($input['brazo_habil']) : 'Diestro';
    $diasPreferidos = isset($input['dias_preferidos']) ? (is_array($input['dias_preferidos']) ? json_encode($input['dias_preferidos']) : $input['dias_preferidos']) : '["1","3","5"]';
    $franjaHoraria = isset($input['franja_horaria']) ? trim($input['franja_horaria']) : 'tarde';
    $horarioHabitual = isset($input['horario_habitual']) ? trim($input['horario_habitual']) : '19:30';
    $activoMatchmaking = isset($input['activo_matchmaking']) ? (int)$input['activo_matchmaking'] : 1;
    $notas = isset($input['notas']) ? trim($input['notas']) : '';

    if (!$jugadorId) {
        @ob_clean();
        echo json_encode(["success" => false, "error" => "usuario_id es requerido"]);
        exit;
    }

    // Ensure table exists
    $sqlTable = "CREATE TABLE IF NOT EXISTS jugador_preferencias_padel (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        club_id INT DEFAULT NULL,
        posicion ENUM('Drive', 'Reves', 'Ambos') DEFAULT 'Ambos',
        categoria VARCHAR(50) DEFAULT '4ª',
        brazo_habil ENUM('Diestro', 'Zurdo') DEFAULT 'Diestro',
        dias_preferidos VARCHAR(255) DEFAULT '[\"1\",\"3\",\"5\"]',
        franja_horaria VARCHAR(50) DEFAULT 'tarde',
        horario_habitual VARCHAR(50) DEFAULT '19:30',
        activo_matchmaking TINYINT(1) DEFAULT 1,
        notas TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_club (usuario_id, club_id),
        INDEX idx_user (usuario_id),
        INDEX idx_club (club_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    @$conn->query($sqlTable);

    // Auto calculate franjaHoraria from horarioHabitual
    $hourNum = (int)explode(':', $horarioHabitual)[0];
    if ($hourNum < 12) $franjaHoraria = 'manana';
    else if ($hourNum < 16) $franjaHoraria = 'mediodia';
    else if ($hourNum < 20) $franjaHoraria = 'tarde';
    else $franjaHoraria = 'noche';

    // Delete any existing preference for this user to avoid stale/duplicate entries
    $conn->query("DELETE FROM jugador_preferencias_padel WHERE usuario_id = $jugadorId AND (club_id = $clubId OR club_id = 0 OR club_id IS NULL)");

    // Insert updated preferences
    $sql = "INSERT INTO jugador_preferencias_padel 
                (usuario_id, club_id, posicion, categoria, brazo_habil, dias_preferidos, franja_horaria, horario_habitual, activo_matchmaking, notas)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("iissssssis", $jugadorId, $clubId, $posicion, $categoria, $brazoHabil, $diasPreferidos, $franjaHoraria, $horarioHabitual, $activoMatchmaking, $notas);
        $stmt->execute();
    }

    // Ensure column categoria in usuarios
    $chk = @$conn->query("SHOW COLUMNS FROM usuarios LIKE 'categoria'");
    if ($chk && $chk->num_rows == 0) {
        @$conn->query("ALTER TABLE usuarios ADD `categoria` VARCHAR(50) DEFAULT '4ª'");
    }

    // Update user's category in usuarios table
    $stmtUser = @$conn->prepare("UPDATE usuarios SET categoria = ? WHERE id = ?");
    if ($stmtUser) {
        $stmtUser->bind_param("si", $categoria, $jugadorId);
        $stmtUser->execute();
    }

    @ob_clean();
    echo json_encode([
        "success" => true,
        "message" => "Preferencias de juego actualizadas correctamente"
    ]);
    exit;

} catch (Throwable $e) {
    @ob_clean();
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
    exit;
}
?>
