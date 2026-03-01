<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);

$categoria_id = $data['categoria_id'] ?? 0;
$jugador1_id = $data['jugador1_id'] ?: null;
$jugador2_id = $data['jugador2_id'] ?: null;
$j1_nombre_manual = $data['jugador1_nombre_manual'] ?? '';
$j2_nombre_manual = $data['jugador2_nombre_manual'] ?? '';
$nombre_pareja = $data['nombre_pareja'] ?? '';
$es_admin = $data['es_admin'] ?? false; // Si lo inscribe el club

if (!$categoria_id) {
    http_response_code(400);
    echo json_encode(["error" => "Categoría no especificada"]);
    exit;
}

// 1. Buscar o crear la pareja
// Buscamos si ya existe esta combinación (con IDs o con nombres manuales)
if ($jugador1_id && $jugador2_id) {
    $sqlPal = "SELECT id FROM torneo_parejas WHERE (jugador1_id = ? AND jugador2_id = ?) OR (jugador1_id = ? AND jugador2_id = ?)";
    $stmtPal = $conn->prepare($sqlPal);
    $stmtPal->bind_param("iiii", $jugador1_id, $jugador2_id, $jugador2_id, $jugador1_id);
} else {
    // Si hay nombres manuales, buscamos por nombre o simplemente creamos una nueva para evitar conflictos de homónimos
    $pareja_id = 0;
}

if (isset($stmtPal)) {
    $stmtPal->execute();
    $resPal = $stmtPal->get_result();
    if ($resPal->num_rows > 0) {
        $pareja_id = $resPal->fetch_assoc()['id'];
    }
}

if (empty($pareja_id)) {
    $sqlInsP = "INSERT INTO torneo_parejas (jugador1_id, jugador1_nombre_manual, jugador2_id, jugador2_nombre_manual, nombre_pareja) VALUES (?, ?, ?, ?, ?)";
    $stmtInsP = $conn->prepare($sqlInsP);
    $stmtInsP->bind_param("isiss", $jugador1_id, $j1_nombre_manual, $jugador2_id, $j2_nombre_manual, $nombre_pareja);
    $stmtInsP->execute();
    $pareja_id = $conn->insert_id;
}

// 2. Verificar si ya está inscrita en esta categoría para evitar duplicados
$sqlCheck = "SELECT id FROM torneo_inscripciones WHERE categoria_id = ? AND pareja_id = ?";
$stmtCheck = $conn->prepare($sqlCheck);
$stmtCheck->bind_param("ii", $categoria_id, $pareja_id);
$stmtCheck->execute();
if ($stmtCheck->get_result()->num_rows > 0) {
    echo json_encode(["success" => true, "mensaje" => "La pareja ya estaba inscrita"]); // No fallar si ya estaba
    exit;
}

// 3. Crear inscripción
$validado = $es_admin ? 1 : 0;
$pagado = $es_admin ? 1 : 0;

$sqlInsI = "INSERT INTO torneo_inscripciones (categoria_id, pareja_id, pagado, validado) VALUES (?, ?, ?, ?)";
$stmtInsI = $conn->prepare($sqlInsI);
$stmtInsI->bind_param("iiii", $categoria_id, $pareja_id, $pagado, $validado);

if ($stmtInsI->execute()) {
    echo json_encode(["success" => true, "mensaje" => "Inscripción realizada exitosamente"]);
} else {
    http_response_code(500);
    echo json_encode(["error" => $conn->error]);
}
?>
