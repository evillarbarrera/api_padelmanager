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

    $canchaId = isset($input['cancha_id']) ? (int)$input['cancha_id'] : 0;
    $fecha = isset($input['fecha']) ? trim($input['fecha']) : date('Y-m-d');
    $horaInicio = isset($input['hora_inicio']) ? trim($input['hora_inicio']) : '19:30:00';
    $horaFin = isset($input['hora_fin']) ? trim($input['hora_fin']) : '21:00:00';
    $precio = isset($input['precio']) ? (float)$input['precio'] : 0.00;
    $categoria = isset($input['categoria']) ? trim($input['categoria']) : '4ª';

    $j1 = isset($input['jugador1_id']) && (int)$input['jugador1_id'] > 0 ? (int)$input['jugador1_id'] : null;
    $j2 = isset($input['jugador2_id']) && (int)$input['jugador2_id'] > 0 ? (int)$input['jugador2_id'] : null;
    $j3 = isset($input['jugador3_id']) && (int)$input['jugador3_id'] > 0 ? (int)$input['jugador3_id'] : null;
    $j4 = isset($input['jugador4_id']) && (int)$input['jugador4_id'] > 0 ? (int)$input['jugador4_id'] : null;

    $nom1 = isset($input['jugador1_nombre']) ? trim($input['jugador1_nombre']) : '';
    $nom2 = isset($input['jugador2_nombre']) ? trim($input['jugador2_nombre']) : '';
    $nom3 = isset($input['jugador3_nombre']) ? trim($input['jugador3_nombre']) : '';
    $nom4 = isset($input['jugador4_nombre']) ? trim($input['jugador4_nombre']) : '';

    if (!$canchaId) {
        @ob_clean();
        echo json_encode(["success" => false, "error" => "cancha_id es requerido"]);
        exit;
    }

    // Silent schema fix on reservas_cancha
    function ensureColumnRC($conn, $col, $def) {
        $c = @$conn->query("SHOW COLUMNS FROM reservas_cancha LIKE '$col'");
        if ($c && $c->num_rows == 0) {
            @$conn->query("ALTER TABLE reservas_cancha ADD `$col` $def");
        }
    }
    ensureColumnRC($conn, 'usuario_id', "INT NULL");
    ensureColumnRC($conn, 'jugador2_id', "INT NULL");
    ensureColumnRC($conn, 'jugador3_id', "INT NULL");
    ensureColumnRC($conn, 'jugador4_id', "INT NULL");
    ensureColumnRC($conn, 'nombre_externo', "VARCHAR(150) NULL");
    ensureColumnRC($conn, 'nombre_externo2', "VARCHAR(150) NULL");
    ensureColumnRC($conn, 'nombre_externo3', "VARCHAR(150) NULL");
    ensureColumnRC($conn, 'nombre_externo4', "VARCHAR(150) NULL");
    ensureColumnRC($conn, 'precio_total', "DECIMAL(10,2) DEFAULT 0.00");
    ensureColumnRC($conn, 'tipo_reserva', "VARCHAR(50) DEFAULT 'cancha'");
    ensureColumnRC($conn, 'categoria', "VARCHAR(50) NULL");

    // Check collision
    $checkCol = @$conn->query("SELECT id FROM reservas_cancha 
                              WHERE cancha_id = $canchaId 
                                AND fecha = '$fecha' 
                                AND hora_inicio = '$horaInicio' 
                                AND (estado IS NULL OR LOWER(estado) NOT IN ('cancelada', 'cancelado', 'anulada', 'anulado'))");

    if ($checkCol && $checkCol->num_rows > 0) {
        @ob_clean();
        echo json_encode([
            "success" => false, 
            "error" => "La cancha ya tiene una reserva en ese horario. Por favor selecciona otra cancha o franja horaria."
        ]);
        exit;
    }

    $sql = "INSERT INTO reservas_cancha 
                (cancha_id, usuario_id, jugador2_id, jugador3_id, jugador4_id,
                 nombre_externo, nombre_externo2, nombre_externo3, nombre_externo4,
                 fecha, hora_inicio, hora_fin, precio, precio_total, pagado, estado, tipo_reserva, categoria)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'Confirmada', 'partido_matchmaking', ?)";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("iiiiisssssssdds", $canchaId, $j1, $j2, $j3, $j4, $nom1, $nom2, $nom3, $nom4, $fecha, $horaInicio, $horaFin, $precio, $precio, $categoria);
        $stmt->execute();
        $reservaId = $conn->insert_id;

        @ob_clean();
        echo json_encode([
            "success" => true,
            "message" => "¡Partido agendado y cancha reservada exitosamente!",
            "reserva_id" => $reservaId
        ]);
        exit;
    } else {
        @ob_clean();
        echo json_encode([
            "success" => false,
            "error" => "Error preparando registro de reserva: " . $conn->error
        ]);
        exit;
    }

} catch (Throwable $e) {
    @ob_clean();
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
    exit;
}
?>
