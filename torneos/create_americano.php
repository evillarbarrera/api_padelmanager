<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
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

$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    http_response_code(400);
    echo json_encode(["error" => "Datos inválidos"]);
    exit;
}

$club_id = $data['club_id'] ?? 0;
$nombre = $data['nombre'] ?? '';
$fecha = $data['fecha'] ?? '';
$hora_inicio = $data['hora_inicio'] ?? '';
$num_canchas = $data['num_canchas'] ?? 1;
$tiempo_por_partido = $data['tiempo_por_partido'] ?? 20;
$puntos_ganado = $data['puntos_ganado'] ?? 3;
$puntos_empate = $data['puntos_empate'] ?? 1;
$puntos_1 = $data['puntos_1er_lugar'] ?? 100;
$puntos_2 = $data['puntos_2do_lugar'] ?? 60;
$puntos_3 = $data['puntos_3er_lugar'] ?? 40;
$puntos_4 = $data['puntos_4to_lugar'] ?? 20;
$puntos_part = $data['puntos_participacion'] ?? 5;
$precio = isset($data['precio']) ? floatval($data['precio']) : 0;

$categoria = $data['categoria'] ?? 'Cuarta';

if (empty($club_id) || empty($nombre) || empty($fecha) || empty($hora_inicio)) {
    http_response_code(400);
    echo json_encode(["error" => "Faltan campos obligatorios (club_id, nombre, fecha, hora_inicio)"]);
    exit;
}

/**
 * SILENT SCHEMA FIX - Ensure all columns exist before insert
 */
function ensureColumn($conn, $table, $column, $definition) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($check && $check->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD `$column` $definition");
    }
}

// Ensure category and points columns exist
ensureColumn($conn, 'torneos_americanos', 'categoria', "VARCHAR(50) DEFAULT 'Cuarta'");
ensureColumn($conn, 'torneos_americanos', 'puntos_1er_lugar', "INT DEFAULT 100");
ensureColumn($conn, 'torneos_americanos', 'puntos_2do_lugar', "INT DEFAULT 60");
ensureColumn($conn, 'torneos_americanos', 'puntos_3er_lugar', "INT DEFAULT 40");
ensureColumn($conn, 'torneos_americanos', 'puntos_4to_lugar', "INT DEFAULT 20");
ensureColumn($conn, 'torneos_americanos', 'puntos_participacion', "INT DEFAULT 5");
ensureColumn($conn, 'torneos_americanos', 'estado', "ENUM('Abierto', 'Cerrado') DEFAULT 'Abierto'");
ensureColumn($conn, 'torneos_americanos', 'tipo_torneo', "ENUM('estandar', 'grupos') DEFAULT 'estandar'");
ensureColumn($conn, 'torneos_americanos', 'modalidad', "ENUM('unicategoria', 'suma', 'mixto') DEFAULT 'unicategoria'");
ensureColumn($conn, 'torneos_americanos', 'valor_suma', "INT NULL");
ensureColumn($conn, 'torneos_americanos', 'genero', "VARCHAR(20) NULL");
ensureColumn($conn, 'torneos_americanos', 'precio', "DECIMAL(10,2) DEFAULT 0.00");

$creator_id = $data['creator_id'] ?? 0;
$tipo_torneo = $data['tipo_torneo'] ?? 'estandar';
$modalidad = $data['modalidad'] ?? 'unicategoria';
$valor_suma = $data['valor_suma'] ?? null;
$genero = $data['genero'] ?? null;

$max_parejas = $data['max_parejas'] ?? 8;

// Calcular duración total del americano en minutos
$num_c = max(1, (int)$num_canchas);
$t_part = max(10, (int)$tiempo_por_partido);
$max_p = max(4, (int)$max_parejas);

if ($tipo_torneo === 'grupos' && $max_p === 8) {
    $total_partidos = 14;
    $slots = ceil($total_partidos / $num_c);
    $duracion_min = $slots * $t_part;
} else {
    $total_partidos = ($max_p * ($max_p - 1)) / 2;
    $slots = ceil($total_partidos / $num_c);
    $duracion_min = $slots * $t_part;
}
// Mínimo 90 min, máximo 360 min y múltiplo de 30 min para alineación de grilla perfecta
$duracion_min = max(90, min(360, (int)$duracion_min));
$duracion_min = ceil($duracion_min / 30) * 30;

$start_ts = strtotime($hora_inicio);
$end_ts = $start_ts + ($duracion_min * 60);
$h_ini = date('H:i:s', $start_ts);
$h_fin = date('H:i:s', $end_ts);
if (date('H:i', $end_ts) === '00:00' || (date('H', $end_ts) == '00' && date('i', $end_ts) == '00')) {
    $h_fin = '24:00:00';
}

// Obtener canchas a reservar
$canchas_a_reservar = [];
if (!empty($data['canchas_ids']) && is_array($data['canchas_ids'])) {
    $canchas_a_reservar = array_map('intval', $data['canchas_ids']);
} else {
    $sqlC = "SELECT id FROM canchas WHERE club_id = ? AND activa = 1 ORDER BY id ASC LIMIT ?";
    $stmtC = $conn->prepare($sqlC);
    $stmtC->bind_param("ii", $club_id, $num_c);
    $stmtC->execute();
    $resC = $stmtC->get_result();
    while ($rowC = $resC->fetch_assoc()) {
        $canchas_a_reservar[] = (int)$rowC['id'];
    }
}

// 1. Validar que ninguna cancha seleccionada choque con una reserva activa
foreach ($canchas_a_reservar as $cid) {
    if ($cid > 0) {
        $stmtChk = $conn->prepare("SELECT r.id, r.hora_inicio, r.hora_fin, c.nombre FROM reservas_cancha r JOIN canchas c ON r.cancha_id = c.id WHERE r.cancha_id = ? AND r.fecha = ? AND LOWER(r.estado) NOT IN ('cancelada', 'cancelado', 'anulada', 'anulado', 'rechazada', 'rechazado', 'eliminada', 'eliminado') AND TIME(r.hora_inicio) < TIME(?) AND TIME(CASE WHEN r.hora_fin IN ('00:00:00','00:00','24:00','24:00:00','23:59','23:59:00') OR TIME(r.hora_fin) <= TIME(r.hora_inicio) THEN '24:00:00' ELSE r.hora_fin END) > TIME(?) LIMIT 1");
        if ($stmtChk) {
            $stmtChk->bind_param("isss", $cid, $fecha, $h_fin, $h_ini);
            $stmtChk->execute();
            $resChk = $stmtChk->get_result();
            if ($resChk && $rowChk = $resChk->fetch_assoc()) {
                http_response_code(409);
                echo json_encode([
                    "error" => "La cancha '" . $rowChk['nombre'] . "' ya se encuentra reservada el $fecha entre " . substr($rowChk['hora_inicio'], 0, 5) . " y " . substr($rowChk['hora_fin'], 0, 5) . "."
                ]);
                exit;
            }
            $stmtChk->close();
        }
    }
}

$sql = "INSERT INTO torneos_americanos 
        (club_id, creator_id, nombre, fecha, hora_inicio, num_canchas, tiempo_por_partido, puntos_ganado, puntos_empate, 
         puntos_1er_lugar, puntos_2do_lugar, puntos_3er_lugar, puntos_4to_lugar, puntos_participacion, categoria,
         tipo_torneo, modalidad, valor_suma, genero, max_parejas, precio) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => "Error preparando DB: " . $conn->error]);
    exit;
}

$stmt->bind_param("iisssiiiiiiiiisssisid", $club_id, $creator_id, $nombre, $fecha, $hora_inicio, $num_canchas, $tiempo_por_partido, 
                  $puntos_ganado, $puntos_empate, $puntos_1, $puntos_2, $puntos_3, $puntos_4, $puntos_part, $categoria,
                  $tipo_torneo, $modalidad, $valor_suma, $genero, $max_parejas, $precio);

if ($stmt->execute()) {
    $torneo_id = $conn->insert_id;

    // Ensure columns exist in reservas_cancha
    ensureColumn($conn, 'reservas_cancha', 'torneo_id', "INT NULL");
    ensureColumn($conn, 'reservas_cancha', 'tipo_reserva', "VARCHAR(50) NULL");
    ensureColumn($conn, 'reservas_cancha', 'nombre_externo', "VARCHAR(255) NULL");

    // Insertar reserva de bloque completo para cada cancha
    foreach ($canchas_a_reservar as $cid) {
        if ($cid > 0) {
            $sqlRes = "INSERT INTO reservas_cancha 
                      (cancha_id, usuario_id, fecha, hora_inicio, hora_fin, precio, estado, tipo_reserva, nombre_externo, torneo_id) 
                      VALUES (?, ?, ?, ?, ?, 0, 'Americano', 'americano', ?, ?)";
            $stmtRes = $conn->prepare($sqlRes);
            if ($stmtRes) {
                $nomRes = !empty($nombre) ? $nombre : "Torneo Americano";
                $stmtRes->bind_param("iissssi", $cid, $creator_id, $fecha, $h_ini, $h_fin, $nomRes, $torneo_id);
                $stmtRes->execute();
            }
        }
    }

    echo json_encode(["success" => true, "id" => $torneo_id, "duracion_minutos" => $duracion_min, "hora_fin" => $h_fin]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al ejecutar insert: " . $stmt->error, "sql_error" => $conn->error]);
}
?>
