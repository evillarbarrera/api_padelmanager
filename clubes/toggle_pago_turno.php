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
    $pagado = (!empty($data['pagado']) && $data['pagado'] !== 'false' && $data['pagado'] !== 0 && $data['pagado'] !== '0') ? 1 : 0;
    $monto_pago = (isset($data['monto_pago']) && $data['monto_pago'] !== '' && $data['monto_pago'] !== null) ? intval($data['monto_pago']) : null;
    $metodo_pago = isset($data['metodo_pago']) ? trim($data['metodo_pago']) : '';
    if (empty($metodo_pago) && $pagado) {
        $metodo_pago = 'Transferencia';
    }

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(["error" => "ID del turno es requerido"]);
        exit;
    }

    // Auto-migración individual y segura de columnas compatibles con todas las versiones de MySQL
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

    $fecha_pago = $pagado ? date('Y-m-d H:i:s') : null;

    if ($club_id > 0) {
        $stmt = $conn->prepare("UPDATE club_turnos SET pagado = ?, monto_pago = ?, metodo_pago = ?, fecha_pago = ? WHERE id = ? AND club_id = ?");
        if (!$stmt) throw new Exception("Error preparando update: " . $conn->error);
        $stmt->bind_param("iissii", $pagado, $monto_pago, $metodo_pago, $fecha_pago, $id, $club_id);
    } else {
        $stmt = $conn->prepare("UPDATE club_turnos SET pagado = ?, monto_pago = ?, metodo_pago = ?, fecha_pago = ? WHERE id = ?");
        if (!$stmt) throw new Exception("Error preparando update: " . $conn->error);
        $stmt->bind_param("iissi", $pagado, $monto_pago, $metodo_pago, $fecha_pago, $id);
    }

    if ($stmt->execute()) {
        echo json_encode([
            "success" => true,
            "id" => $id,
            "pagado" => $pagado,
            "monto_pago" => $monto_pago,
            "metodo_pago" => $metodo_pago,
            "fecha_pago" => $fecha_pago,
            "message" => $pagado ? "Turno marcado como PAGADO" : "Turno marcado como PENDIENTE"
        ]);
    } else {
        throw new Exception("Error al actualizar estado de pago: " . $stmt->error);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
?>
