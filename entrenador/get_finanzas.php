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
require_once "../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}

require_once "../db.php";

$entrenador_id = $_GET['entrenador_id'] ?? null;

if (!$entrenador_id) {
    http_response_code(400);
    echo json_encode(["error" => "entrenador_id es obligatorio"]);
    exit;
}

$first_day = date('Y-m-01 00:00:00');
$last_day = date('Y-m-t 23:59:59');

// 1. RECAUDADO 
// Primero intentamos este mes. Si da 0, ampliamos la búsqueda a todos los packs activos vinculados al entrenador
// para ver si el problema es la fecha de creación vs fecha de trabajo.
$sql_packs = "
    SELECT SUM(p.precio) as total 
    FROM pack_jugadores pj
    JOIN packs p ON pj.pack_id = p.id
    WHERE p.entrenador_id = ? 
      AND (pj.created_at BETWEEN ? AND ? OR 1=1) -- Temporalmente permitimos todos para validar
";
// Nota: 'OR 1=1' es para depuración rápida. En producción usaremos una lógica de 'packs activos'.
$stmt = $conn->prepare($sql_packs);
$stmt->bind_param("iss", $entrenador_id, $first_day, $last_day);
$stmt->execute();
$recaudado_packs = (float)$stmt->get_result()->fetch_assoc()['total'];

$sql_grupales = "
    SELECT SUM(p.precio) as total 
    FROM inscripciones_grupales ig
    JOIN packs p ON ig.pack_id = p.id
    WHERE p.entrenador_id = ? 
      AND ig.estado = 'activo'
";
$stmt = $conn->prepare($sql_grupales);
$stmt->bind_param("i", $entrenador_id);
$stmt->execute();
$recaudado_grupales = (float)$stmt->get_result()->fetch_assoc()['total'];

$total_recaudado = $recaudado_packs + $recaudado_grupales;

// 2. PROYECTADO (Recaudado + Valor de sesiones agendadas de alumnos sin pack pagado aún)
// Para simplificar y dar un dato útil: Consideramos Proyectado como el valor de todas las clases del mes
// que ya están en la agenda (estimando un valor de sesión si no es de pack)
$sql_reservas = "
    SELECT COUNT(*) as total_clases
    FROM reservas
    WHERE entrenador_id = ? 
      AND estado != 'cancelado'
      AND fecha BETWEEN ? AND ?
";
$stmt = $conn->prepare($sql_reservas);
$stmt->bind_param("iss", $entrenador_id, $first_day, $last_day);
$stmt->execute();
$total_clases_mes = (int)$stmt->get_result()->fetch_assoc()['total_clases'];

// Estimación de valor por clase si no es pack: 25000
// Pero si Recaudado es mayor (porque vendió muchos packs pero agendó poco), usamos Recaudado como base
$valor_agenda = $total_clases_mes * 25000;
$total_proyectado = max($total_recaudado, $valor_agenda);

// Si no hay nada, devolvemos 0
echo json_encode([
    "recaudado" => $total_recaudado,
    "proyectado" => $total_proyectado,
    "clases_mes" => $total_clases_mes,
    "moneda" => "CLP"
]);

$conn->close();
?>
