<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
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

$creator_id = $data['creator_id'] ?? 0;
$tipo_torneo = $data['tipo_torneo'] ?? 'estandar';
$modalidad = $data['modalidad'] ?? 'unicategoria';
$valor_suma = $data['valor_suma'] ?? null;
$genero = $data['genero'] ?? null;

$max_parejas = $data['max_parejas'] ?? 8;

$sql = "INSERT INTO torneos_americanos 
        (club_id, creator_id, nombre, fecha, hora_inicio, num_canchas, tiempo_por_partido, puntos_ganado, puntos_empate, 
         puntos_1er_lugar, puntos_2do_lugar, puntos_3er_lugar, puntos_4to_lugar, puntos_participacion, categoria,
         tipo_torneo, modalidad, valor_suma, genero, max_parejas) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => "Error preparando DB: " . $conn->error]);
    exit;
}

$stmt->bind_param("iisssiiiiiiiiisssisi", $club_id, $creator_id, $nombre, $fecha, $hora_inicio, $num_canchas, $tiempo_por_partido, 
                  $puntos_ganado, $puntos_empate, $puntos_1, $puntos_2, $puntos_3, $puntos_4, $puntos_part, $categoria,
                  $tipo_torneo, $modalidad, $valor_suma, $genero, $max_parejas);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "id" => $conn->insert_id]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al ejecutar insert: " . $stmt->error, "sql_error" => $conn->error]);
}
?>
