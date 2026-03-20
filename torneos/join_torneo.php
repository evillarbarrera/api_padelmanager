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
$torneo_id = $data['torneo_id'] ?? 0;
// Slot 1
$p1_id = $data['jugador1_id'] ?? $data['usuario_id'] ?? null; // Allow null
$p1_name = $data['nombre_1'] ?? $data['nombre_pareja1'] ?? null; // External name

// Slot 2
$p2_id = $data['jugador2_id'] ?? null;
$p2_name = $data['nombre_2'] ?? $data['nombre_pareja2'] ?? null;

// General Pair Name
$nombre_pareja = $data['nombre_pareja'] ?? '';

// Validar Slot 1 (Check if ID exists OR name exists)
if (empty($p1_id) && empty($p1_name)) {
    http_response_code(400);
    echo json_encode(["error" => "Falta el jugador 1 (ID o Nombre)"]);
    exit;
}
// Validar Slot 2
if (empty($p2_id) && empty($p2_name)) {
    http_response_code(400);
    echo json_encode(["error" => "Falta el jugador 2 (ID o Nombre)"]);
    exit;
}

// Convert empty to null for DB
if (empty($p1_id)) $p1_id = null;
if (empty($p2_id)) $p2_id = null;

// Clean names if IDs exist (optional, but good for clarity)
if ($p1_id) $p1_name = null;
if ($p2_id) $p2_name = null;


// Verificar duplicados (solo si hay IDs)
if ($p1_id || $p2_id) {
    // Construct dynamic query parts
    $conditions = [];
    $types = "i";
    $params = [$torneo_id];
    
    // Logic: check if p1_id is already in p1 or p2 slot. Check if p2_id is in p1 or p2 slot.
    // Simplified: Just run query if we have IDs.
    $checkSql = "SELECT id FROM torneo_participantes WHERE torneo_id = ? AND (
        (jugador_id IS NOT NULL AND jugador_id IN (?, ?)) OR 
        (jugador2_id IS NOT NULL AND jugador2_id IN (?, ?))
    )";
    
    // We pass both IDs to both IN clauses. Use 0 if null to avoid syntax error or issues
    $chk_p1 = $p1_id ?: 0;
    $chk_p2 = $p2_id ?: 0;
    
    $stmtC = $conn->prepare($checkSql);
    $stmtC->bind_param("iiiii", $torneo_id, $chk_p1, $chk_p2, $chk_p1, $chk_p2);
    $stmtC->execute();
    if ($stmtC->get_result()->num_rows > 0) {
        http_response_code(400);
        echo json_encode(["error" => "¡Ups! Uno de los jugadores ya se encuentra inscrito en este torneo. Revisa si ya tienes una inscripción activa o si tu pareja ya te inscribió."]);
        exit;
    }
}

$sql = "INSERT INTO torneo_participantes (torneo_id, jugador_id, jugador2_id, nombre_pareja, nombre_externo_1, nombre_externo_2) VALUES (?, ?, ?, ?, ?, ?)";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => "Error DB (Prepare failed): " . $conn->error . ". Posiblemente falten las columnas 'nombre_externo' en la base de datos."]);
    exit;
}

$stmt->bind_param("iiisss", $torneo_id, $p1_id, $p2_id, $nombre_pareja, $p1_name, $p2_name);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "id" => $conn->insert_id]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Error al inscribirse: " . $conn->error]);
}
?>
