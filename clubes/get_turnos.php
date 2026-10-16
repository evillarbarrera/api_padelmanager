<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type, X-Requested-With, Origin, Accept");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once "../db.php";
    require_once "../auth/auth_helper.php";

    if (!validateToken()) {
        sendUnauthorized();
    }

    $club_id = isset($_GET['club_id']) ? intval($_GET['club_id']) : 0;
    if ($club_id <= 0) {
        http_response_code(400);
        echo json_encode(["error" => "ID del club es obligatorio"]);
        exit;
    }

    // 1. Auto-crear tabla si no existe
    $createTableSql = "CREATE TABLE IF NOT EXISTS club_turnos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        club_id INT NOT NULL,
        usuario_id INT NULL,
        nombre_responsable VARCHAR(150) NOT NULL,
        email VARCHAR(150) NULL,
        telefono VARCHAR(50) NULL,
        foto VARCHAR(255) NULL,
        fecha DATE NOT NULL,
        hora_inicio TIME NOT NULL DEFAULT '08:00:00',
        hora_fin TIME NOT NULL DEFAULT '23:30:00',
        tipo_turno VARCHAR(50) DEFAULT 'Día Completo',
        rol_turno VARCHAR(100) DEFAULT 'Encargado de Turno',
        color VARCHAR(20) DEFAULT '#10b981',
        notas TEXT NULL,
        estado ENUM('Programado', 'En Curso', 'Completado', 'Ausente', 'Reemplazado') DEFAULT 'Programado',
        pagado TINYINT(1) NOT NULL DEFAULT 0,
        monto_pago INT NULL,
        metodo_pago VARCHAR(50) NULL,
        fecha_pago DATETIME NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_club_fecha (club_id, fecha)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    @$conn->query($createTableSql);

    // Migraciones seguras e individuales para columnas de pago si la tabla ya existía
    $colsToCheck = [
        'pagado' => "ALTER TABLE club_turnos ADD COLUMN pagado TINYINT(1) NOT NULL DEFAULT 0",
        'monto_pago' => "ALTER TABLE club_turnos ADD COLUMN monto_pago INT NULL",
        'metodo_pago' => "ALTER TABLE club_turnos ADD COLUMN metodo_pago VARCHAR(50) NULL",
        'fecha_pago' => "ALTER TABLE club_turnos ADD COLUMN fecha_pago DATETIME NULL"
    ];

    foreach ($colsToCheck as $col => $alterSql) {
        $checkCol = $conn->query("SHOW COLUMNS FROM club_turnos LIKE '$col'");
        if ($checkCol && $checkCol->num_rows == 0) {
            @$conn->query($alterSql);
        }
    }

    // 2. Filtros de fecha
    $fecha_inicio = !empty($_GET['fecha_inicio']) ? trim($_GET['fecha_inicio']) : null;
    $fecha_fin = !empty($_GET['fecha_fin']) ? trim($_GET['fecha_fin']) : null;
    $mes = isset($_GET['mes']) ? intval($_GET['mes']) : 0;
    $anio = isset($_GET['anio']) ? intval($_GET['anio']) : 0;

    $baseSelect = "SELECT 
                    t.id,
                    t.club_id,
                    t.usuario_id,
                    COALESCE(NULLIF(t.nombre_responsable, ''), u.nombre, 'Encargado de Turno') AS nombre_responsable,
                    COALESCE(NULLIF(t.email, ''), u.usuario, '') AS email,
                    COALESCE(t.telefono, '') AS telefono,
                    COALESCE(t.foto, '') AS foto,
                    t.fecha,
                    DATE_FORMAT(t.hora_inicio, '%H:%i') AS hora_inicio,
                    DATE_FORMAT(t.hora_fin, '%H:%i') AS hora_fin,
                    t.tipo_turno,
                    t.rol_turno,
                    t.color,
                    t.notas,
                    t.estado,
                    CAST(COALESCE(t.pagado, 0) AS UNSIGNED) AS pagado,
                    t.monto_pago,
                    COALESCE(t.metodo_pago, '') AS metodo_pago,
                    t.fecha_pago,
                    t.created_by,
                    t.created_at,
                    t.updated_at
                FROM club_turnos t
                LEFT JOIN usuarios u ON t.usuario_id = u.id";

    if ($fecha_inicio && $fecha_fin) {
        $stmt = $conn->prepare("$baseSelect WHERE t.club_id = ? AND t.fecha BETWEEN ? AND ? ORDER BY t.fecha ASC, t.hora_inicio ASC");
        if (!$stmt) throw new Exception("Error prepare: " . $conn->error);
        $stmt->bind_param("iss", $club_id, $fecha_inicio, $fecha_fin);
    } elseif ($mes > 0 && $anio > 0) {
        $startDate = sprintf("%04d-%02d-01", $anio, $mes);
        $endDate = date("Y-m-t", strtotime($startDate));
        $startDateExpanded = date("Y-m-d", strtotime($startDate . " -7 days"));
        $endDateExpanded = date("Y-m-d", strtotime($endDate . " +7 days"));

        $stmt = $conn->prepare("$baseSelect WHERE t.club_id = ? AND t.fecha BETWEEN ? AND ? ORDER BY t.fecha ASC, t.hora_inicio ASC");
        if (!$stmt) throw new Exception("Error prepare: " . $conn->error);
        $stmt->bind_param("iss", $club_id, $startDateExpanded, $endDateExpanded);
    } else {
        $stmt = $conn->prepare("$baseSelect WHERE t.club_id = ? ORDER BY t.fecha ASC, t.hora_inicio ASC");
        if (!$stmt) throw new Exception("Error prepare: " . $conn->error);
        $stmt->bind_param("i", $club_id);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $turnos = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['club_id'] = (int)$row['club_id'];
            $row['usuario_id'] = $row['usuario_id'] !== null ? (int)$row['usuario_id'] : null;
            $row['pagado'] = (int)$row['pagado'];
            $row['monto_pago'] = $row['monto_pago'] !== null ? (int)$row['monto_pago'] : null;
            $turnos[] = $row;
        }
    }

    echo json_encode([
        "success" => true,
        "club_id" => $club_id,
        "total" => count($turnos),
        "turnos" => $turnos
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
?>
