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
    require_once "../auth/auth_helper.php";
    if (!validateToken()) {
        sendUnauthorized();
    }

    require_once "../db.php";

    $data = json_decode(file_get_contents("php://input"), true);
    if (!$data) {
        http_response_code(400);
        echo json_encode(["error" => "Payload JSON inválido"]);
        exit;
    }

    $id = isset($data['id']) ? intval($data['id']) : 0;
    $club_id = isset($data['club_id']) ? intval($data['club_id']) : 0;
    $usuario_id = !empty($data['usuario_id']) ? intval($data['usuario_id']) : null;
    $nombre_responsable = trim($data['nombre_responsable'] ?? '');
    $email = trim($data['email'] ?? '');
    $telefono = trim($data['telefono'] ?? '');
    $foto = trim($data['foto'] ?? '');
    $fecha = trim($data['fecha'] ?? '');
    $hora_inicio = trim($data['hora_inicio'] ?? '08:00');
    $hora_fin = trim($data['hora_fin'] ?? '23:30');
    $tipo_turno = trim($data['tipo_turno'] ?? 'Día Completo');
    $rol_turno = trim($data['rol_turno'] ?? 'Encargado de Turno');
    $color = trim($data['color'] ?? '#10b981');
    $notas = trim($data['notas'] ?? '');
    $estado = trim($data['estado'] ?? 'Programado');
    $pagado = (!empty($data['pagado']) && $data['pagado'] !== 'false' && $data['pagado'] !== 0 && $data['pagado'] !== '0') ? 1 : 0;
    $monto_pago = (isset($data['monto_pago']) && $data['monto_pago'] !== '' && $data['monto_pago'] !== null) ? intval($data['monto_pago']) : null;
    $metodo_pago = trim($data['metodo_pago'] ?? 'Transferencia');
    $fecha_pago = ($pagado && empty($data['fecha_pago'])) ? date('Y-m-d H:i:s') : (!empty($data['fecha_pago']) ? $data['fecha_pago'] : null);
    if (!$pagado) {
        $fecha_pago = null;
    }
    $created_by = !empty($data['created_by']) ? intval($data['created_by']) : null;

    if ($club_id <= 0 || empty($fecha)) {
        http_response_code(400);
        echo json_encode(["error" => "El club_id y la fecha son campos requeridos"]);
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

    // 2. Si viene un usuario_id pero falta el nombre, completarlo desde la tabla usuarios
    if ($usuario_id && empty($nombre_responsable)) {
        $stmtU = $conn->prepare("SELECT nombre, usuario as email FROM usuarios WHERE id = ?");
        if ($stmtU) {
            $stmtU->bind_param("i", $usuario_id);
            $stmtU->execute();
            $resU = $stmtU->get_result();
            if ($rowU = $resU->fetch_assoc()) {
                $nombre_responsable = $rowU['nombre'];
                if (empty($email)) $email = $rowU['email'];
            }
        }
    }

    if (empty($nombre_responsable)) {
        $nombre_responsable = 'Encargado de Turno';
    }

    // Formatear horas a formato HH:MM:SS
    if (strlen($hora_inicio) === 5) $hora_inicio .= ':00';
    if (strlen($hora_fin) === 5) $hora_fin .= ':00';

    if ($id > 0) {
        // ACTUALIZAR TURNO EXISTENTE
        $sql = "UPDATE club_turnos SET 
                    club_id = ?,
                    usuario_id = ?,
                    nombre_responsable = ?,
                    email = ?,
                    telefono = ?,
                    foto = ?,
                    fecha = ?,
                    hora_inicio = ?,
                    hora_fin = ?,
                    tipo_turno = ?,
                    rol_turno = ?,
                    color = ?,
                    notas = ?,
                    estado = ?,
                    pagado = ?,
                    monto_pago = ?,
                    metodo_pago = ?,
                    fecha_pago = ?
                WHERE id = ?";
                
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Error preparando actualización: " . $conn->error);
        }

        $stmt->bind_param(
            "iissssssssssssiissi",
            $club_id,
            $usuario_id,
            $nombre_responsable,
            $email,
            $telefono,
            $foto,
            $fecha,
            $hora_inicio,
            $hora_fin,
            $tipo_turno,
            $rol_turno,
            $color,
            $notas,
            $estado,
            $pagado,
            $monto_pago,
            $metodo_pago,
            $fecha_pago,
            $id
        );

        if ($stmt->execute()) {
            echo json_encode([
                "success" => true,
                "message" => "Turno actualizado correctamente",
                "id" => $id,
                "pagado" => $pagado,
                "monto_pago" => $monto_pago,
                "metodo_pago" => $metodo_pago,
                "fecha_pago" => $fecha_pago
            ]);
        } else {
            throw new Exception("Error al ejecutar actualización: " . $stmt->error);
        }
    } else {
        // INSERTAR NUEVO TURNO
        $sql = "INSERT INTO club_turnos (
                    club_id,
                    usuario_id,
                    nombre_responsable,
                    email,
                    telefono,
                    foto,
                    fecha,
                    hora_inicio,
                    hora_fin,
                    tipo_turno,
                    rol_turno,
                    color,
                    notas,
                    estado,
                    pagado,
                    monto_pago,
                    metodo_pago,
                    fecha_pago,
                    created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Error preparando inserción: " . $conn->error);
        }

        $stmt->bind_param(
            "iissssssssssssiissi",
            $club_id,
            $usuario_id,
            $nombre_responsable,
            $email,
            $telefono,
            $foto,
            $fecha,
            $hora_inicio,
            $hora_fin,
            $tipo_turno,
            $rol_turno,
            $color,
            $notas,
            $estado,
            $pagado,
            $monto_pago,
            $metodo_pago,
            $fecha_pago,
            $created_by
        );

        if ($stmt->execute()) {
            $newId = $conn->insert_id;
            echo json_encode([
                "success" => true,
                "message" => "Turno asignado exitosamente",
                "id" => $newId
            ]);
        } else {
            throw new Exception("Error al ejecutar inserción: " . $stmt->error);
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
?>
