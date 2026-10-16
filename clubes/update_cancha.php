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

// SILENT SCHEMA FIX
function ensureCanchaColumns($conn) {
    $cols = [
        'categoria' => "VARCHAR(50) DEFAULT 'cancha_padel'",
        'icono' => "VARCHAR(50) DEFAULT '🎾'",
        'descripcion' => "TEXT NULL",
        'capacidad' => "INT DEFAULT 4",
        'precio_60_alto' => "DECIMAL(10,2) DEFAULT 0.00",
        'precio_90_alto' => "DECIMAL(10,2) DEFAULT 0.00",
        'precio_120_alto' => "DECIMAL(10,2) DEFAULT 0.00",
        'hora_inicio_bajo' => "TIME DEFAULT '07:00:00'",
        'hora_fin_bajo' => "TIME DEFAULT '18:00:00'",
        'hora_inicio_alto' => "TIME DEFAULT '18:00:00'",
        'hora_fin_alto' => "TIME DEFAULT '24:00:00'",
        'horarios_adicionales' => "TEXT NULL"
    ];
    foreach ($cols as $col => $def) {
        $check = $conn->query("SHOW COLUMNS FROM canchas LIKE '$col'");
        if ($check && $check->num_rows == 0) {
            $conn->query("ALTER TABLE canchas ADD `$col` $def");
        }
    }
}
ensureCanchaColumns($conn);

$id = $data['id'] ?? 0;
$nombre = $data['nombre'] ?? '';
$tipo = $data['tipo'] ?? 'Outdoor';
$superficie = $data['superficie'] ?? 'Césped Sintético';
$categoria = !empty($data['categoria']) ? $data['categoria'] : 'cancha_padel';
$icono = !empty($data['icono']) ? $data['icono'] : ($categoria === 'mesa_pool' ? '🎱' : ($categoria === 'mesa_pingpong' ? '🏓' : ($categoria === 'quincho' ? '🍖' : '🎾')));
$descripcion = $data['descripcion'] ?? '';
$capacidad = isset($data['capacidad']) ? intval($data['capacidad']) : 4;

// Precios regulares (Horario Bajo)
$p60 = $data['precio_60'] ?? 0;
$p90 = $data['precio_90'] ?? 0;
$p120 = $data['precio_120'] ?? 0;

// Precios Horario Alto
$p60_alto = $data['precio_60_alto'] ?? 0;
$p90_alto = $data['precio_90_alto'] ?? 0;
$p120_alto = $data['precio_120_alto'] ?? 0;

$h_inicio_bajo = !empty($data['hora_inicio_bajo']) ? $data['hora_inicio_bajo'] : '07:00:00';
$h_fin_bajo = !empty($data['hora_fin_bajo']) ? $data['hora_fin_bajo'] : '18:00:00';
$h_inicio_alto = !empty($data['hora_inicio_alto']) ? $data['hora_inicio_alto'] : '18:00:00';
$h_fin_alto = !empty($data['hora_fin_alto']) ? $data['hora_fin_alto'] : '24:00:00';

$tramos = $data['tramos_horarios'] ?? [];
$horarios_adicionales_str = !empty($tramos) ? json_encode($tramos) : ($data['horarios_adicionales'] ?? null);
if (is_array($horarios_adicionales_str)) $horarios_adicionales_str = json_encode($horarios_adicionales_str);

if (strlen($h_inicio_bajo) == 5) $h_inicio_bajo .= ':00';
if (strlen($h_fin_bajo) == 5) $h_fin_bajo .= ':00';
if (strlen($h_inicio_alto) == 5) $h_inicio_alto .= ':00';
if (strlen($h_fin_alto) == 5) $h_fin_alto .= ':00';

if (is_array($tramos) && count($tramos) > 0) {
    for ($i = 0; $i < count($tramos); $i++) {
        $h1 = substr($tramos[$i]['hora_inicio'], 0, 5);
        $h2 = substr($tramos[$i]['hora_fin'], 0, 5);
        if ($h2 === '00:00') $h2 = '24:00';
        if ($h1 >= $h2) {
            http_response_code(400);
            echo json_encode(["error" => "Rango de hora inválido en " . ($tramos[$i]['nombre'] ?? 'Horario')]);
            exit;
        }
        for ($j = $i + 1; $j < count($tramos); $j++) {
            $h3 = substr($tramos[$j]['hora_inicio'], 0, 5);
            $h4 = substr($tramos[$j]['hora_fin'], 0, 5);
            if ($h4 === '00:00') $h4 = '24:00';
            if ($h1 < $h4 && $h3 < $h2) {
                http_response_code(400);
                echo json_encode(["error" => "Los horarios '" . ($tramos[$i]['nombre'] ?? 'Tramo '.($i+1)) . "' y '" . ($tramos[$j]['nombre'] ?? 'Tramo '.($j+1)) . "' se traslapan entre sí."]);
                exit;
            }
        }
    }
} else {
    $h_fin_bajo_cmp = ($h_fin_bajo === '00:00:00' || $h_fin_bajo === '00:00') ? '24:00:00' : $h_fin_bajo;
    $h_fin_alto_cmp = ($h_fin_alto === '00:00:00' || $h_fin_alto === '00:00') ? '24:00:00' : $h_fin_alto;

    if ($h_inicio_bajo >= $h_fin_bajo_cmp || $h_inicio_alto >= $h_fin_alto_cmp) {
        http_response_code(400);
        echo json_encode(["error" => "Los rangos de hora no son válidos (la hora de inicio debe ser menor que la de fin)."]);
        exit;
    }

    if ($h_inicio_bajo < $h_fin_alto_cmp && $h_inicio_alto < $h_fin_bajo_cmp) {
        http_response_code(400);
        echo json_encode(["error" => "El Horario Bajo y el Horario Alto se traslapan entre sí."]);
        exit;
    }
}

if (empty($id) || empty($nombre)) {
    http_response_code(400);
    echo json_encode(["error" => "ID y nombre son obligatorios"]);
    exit;
}

$sql = "UPDATE canchas SET nombre = ?, tipo = ?, superficie = ?, categoria = ?, icono = ?, descripcion = ?, capacidad = ?, precio_60 = ?, precio_90 = ?, precio_120 = ?, precio_60_alto = ?, precio_90_alto = ?, precio_120_alto = ?, hora_inicio_bajo = ?, hora_fin_bajo = ?, hora_inicio_alto = ?, hora_fin_alto = ?, horarios_adicionales = ? WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ssssssiddddddsssssi", $nombre, $tipo, $superficie, $categoria, $icono, $descripcion, $capacidad, $p60, $p90, $p120, $p60_alto, $p90_alto, $p120_alto, $h_inicio_bajo, $h_fin_bajo, $h_inicio_alto, $h_fin_alto, $horarios_adicionales_str, $id);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Recurso/Cancha actualizada"]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al actualizar recurso/cancha: " . $conn->error]);
}
?>
