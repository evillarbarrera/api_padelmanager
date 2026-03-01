<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$headers = getallheaders();
$auth = $headers['Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

if ($auth !== 'Bearer ' . base64_encode("1|padel_academy")) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

require_once "../db.php";

$cancha_id = $_GET['cancha_id'] ?? 0;
$fecha = $_GET['fecha'] ?? date('Y-m-d');

if (!$cancha_id) {
    http_response_code(400);
    echo json_encode(["error" => "cancha_id es requerido"]);
    exit;
}

// 1. Obtener configuración de horarios para el día de la semana
$dia_semana = date('w', strtotime($fecha));
$sqlConfig = "SELECT * FROM cancha_horarios_config WHERE cancha_id = ? AND dia_semana = ?";
$stmtConfig = $conn->prepare($sqlConfig);
$stmtConfig->bind_param("ii", $cancha_id, $dia_semana);
$stmtConfig->execute();
$resConfig = $stmtConfig->get_result();

$horarios_disponibles = [];

if ($resConfig->num_rows == 0) {
    // FALLBACK: Si no hay configuración, generar bloques cada 30 min (06:00 - 23:30)
    $inicio = strtotime("06:00:00");
    $fin = strtotime("23:30:00");
    $bloque = 30 * 60; // 30 min
    
    for ($t = $inicio; $t < $fin; $t += $bloque) {
        $h_inicio = date('H:i:s', $t);
        // El bloque para mostrar es de 30 min, pero la reserva puede ser más larga.
        // Aquí solo mostramos el inicio del bloque.
        $h_fin_slot = date('H:i:s', $t + $bloque);

        // Check if this slot is covered by ANY reservation (overlap check)
        $sqlCheck = "SELECT id FROM reservas_cancha 
                     WHERE cancha_id = ? AND fecha = ? 
                     AND estado != 'Cancelada'
                     AND (
                        (hora_inicio <= ? AND hora_fin > ?)
                     )";
        $stmtCheck = $conn->prepare($sqlCheck);
        $stmtCheck->bind_param("isss", $cancha_id, $fecha, $h_inicio, $h_inicio);
        $stmtCheck->execute();
        $is_reserved = $stmtCheck->get_result()->num_rows > 0;

        $horarios_disponibles[] = [
            "hora_inicio" => $h_inicio,
            "hora_fin" => $h_fin_slot,
            "disponible" => !$is_reserved
        ];
    }
} else {
    while ($config = $resConfig->fetch_assoc()) {
        $inicio = strtotime($config['hora_inicio']);
        $fin = strtotime($config['hora_fin']);
        $bloque = $config['duracion_bloque'] * 60; // segundos

        for ($t = $inicio; $t < $fin; $t += $bloque) {
            $h_inicio = date('H:i:s', $t);
            $h_fin = date('H:i:s', $t + $bloque);

            // Check if reserved
            $sqlCheck = "SELECT id FROM reservas_cancha WHERE cancha_id = ? AND fecha = ? AND hora_inicio = ? AND estado != 'Cancelada'";
            $stmtCheck = $conn->prepare($sqlCheck);
            $stmtCheck->bind_param("iss", $cancha_id, $fecha, $h_inicio);
            $stmtCheck->execute();
            $is_reserved = $stmtCheck->get_result()->num_rows > 0;

            $horarios_disponibles[] = [
                "hora_inicio" => $h_inicio,
                "hora_fin" => $h_fin,
                "disponible" => !$is_reserved
            ];
        }
    }
}

echo json_encode($horarios_disponibles);
?>
