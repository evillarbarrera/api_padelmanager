<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$headers = getallheaders();
require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}

require_once "../db.php";

$cancha_id = $_GET['cancha_id'] ?? 0;
$club_id   = $_GET['club_id'] ?? 0;
$fecha     = $_GET['fecha'] ?? date('Y-m-d');

if (!$cancha_id && !$club_id) {
    http_response_code(400);
    echo json_encode(["error" => "cancha_id o club_id es requerido"]);
    exit;
}

$response = [];

// 1. Obtener las canchas involucradas
$canchas_ids = [];
if ($club_id) {
    $sqlC = "SELECT id, nombre FROM canchas WHERE club_id = ?";
    $stmtC = $conn->prepare($sqlC);
    $stmtC->bind_param("i", $club_id);
    $stmtC->execute();
    $resC = $stmtC->get_result();
    while($c = $resC->fetch_assoc()) {
        $canchas_ids[] = $c;
    }
} else {
    $sqlC = "SELECT id, nombre FROM canchas WHERE id = ?";
    $stmtC = $conn->prepare($sqlC);
    $stmtC->bind_param("i", $cancha_id);
    $stmtC->execute();
    $resC = $stmtC->get_result();
    if($c = $resC->fetch_assoc()) {
        $canchas_ids[] = $c;
    }
}

$horarios_mapeados = []; // [ '09:00:00' => [ {cancha_id, cancha_nombre, disponible} ] ]

foreach ($canchas_ids as $cancha) {
    $cid = $cancha['id'];
    $cnombre = $cancha['nombre'];

    $dia_semana = date('w', strtotime($fecha));
    $sqlConfig = "SELECT * FROM cancha_horarios_config WHERE cancha_id = ? AND dia_semana = ?";
    $stmtConfig = $conn->prepare($sqlConfig);
    $stmtConfig->bind_param("ii", $cid, $dia_semana);
    $stmtConfig->execute();
    $resConfig = $stmtConfig->get_result();

    if ($resConfig->num_rows == 0) {
        $inicio = strtotime("07:00:00");
        $fin = strtotime("23:30:00");
        $bloque = 30 * 60; // 30 min default
        for ($t = $inicio; $t < $fin; $t += $bloque) {
            $h_inicio = date('H:i:s', $t);
            $h_fin_slot = date('H:i:s', $t + $bloque);

            $sqlCheck = "SELECT id FROM reservas_cancha WHERE cancha_id = ? AND fecha = ? AND estado != 'Cancelada' AND ((hora_inicio <= ? AND hora_fin > ?))";
            $stmtCheck = $conn->prepare($sqlCheck);
            $stmtCheck->bind_param("isss", $cid, $fecha, $h_inicio, $h_inicio);
            $stmtCheck->execute();
            $is_reserved = $stmtCheck->get_result()->num_rows > 0;

            $horarios_mapeados[$h_inicio][] = [
                "cancha_id" => $cid,
                "cancha_nombre" => $cnombre,
                "disponible" => !$is_reserved,
                "hora_fin" => $h_fin_slot
            ];
        }
    } else {
        while ($config = $resConfig->fetch_assoc()) {
            $inicio = strtotime($config['hora_inicio']);
            $fin = strtotime($config['hora_fin']);
            $bloque = $config['duracion_bloque'] * 60;
            for ($t = $inicio; $t < $fin; $t += $bloque) {
                $h_inicio = date('H:i:s', $t);
                $h_fin = date('H:i:s', $t + $bloque);
                $sqlCheck = "SELECT id FROM reservas_cancha WHERE cancha_id = ? AND fecha = ? AND hora_inicio = ? AND estado != 'Cancelada'";
                $stmtCheck = $conn->prepare($sqlCheck);
                $stmtCheck->bind_param("iss", $cid, $fecha, $h_inicio);
                $stmtCheck->execute();
                $is_reserved = $stmtCheck->get_result()->num_rows > 0;

                $horarios_mapeados[$h_inicio][] = [
                    "cancha_id" => $cid,
                    "cancha_nombre" => $cnombre,
                    "disponible" => !$is_reserved,
                    "hora_fin" => $h_fin
                ];
            }
        }
    }
}

ksort($horarios_mapeados);

// Formatear respuesta para el frontend
$final_response = [];
foreach ($horarios_mapeados as $hora => $canchas_data) {
    $final_response[] = [
        "hora" => $hora,
        "canchas" => $canchas_data
    ];
}

echo json_encode($final_response);
