<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../auth/auth_helper.php";
if (!validateToken()) {
    sendUnauthorized();
}


require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$jugador_id = intval($data['jugador_id'] ?? 0);
$malla_id = intval($data['malla_id'] ?? 0);
$entrenador_id = intval($data['entrenador_id'] ?? 0);
$pack_id = intval($data['pack_id'] ?? 0); // NEW: Group meshes by pack

if (!$jugador_id || !$malla_id || !$entrenador_id) {
    http_response_code(400);
    echo json_encode(["error" => "Datos incompletos"]);
    exit;
}

// 0. Ensure column exists (one-time check, safe for performance)
$conn->query("ALTER TABLE alumno_malla_seguimiento ADD COLUMN IF NOT EXISTS pack_id INT DEFAULT 0 AFTER entrenador_id");

// 0.5 Verificar que no sea un pack grupal
if ($pack_id > 0) {
    $stmtP = $conn->prepare("SELECT tipo FROM packs WHERE id = ?");
    $stmtP->bind_param("i", $pack_id);
    $stmtP->execute();
    $resP = $stmtP->get_result()->fetch_assoc();
    $tipo = $resP['tipo'] ?? 'individual';
    if ($tipo === 'grupal' || $tipo === 'pack_grupal' || $tipo === 'clase grupal') {
        http_response_code(400);
        echo json_encode(["error" => "No se puede asignar una hoja de ruta a un pack grupal."]);
        exit;
    }
}

// 1. Desactivar malla previa del MISMO pack para este alumno
$conn->query("UPDATE alumno_malla_seguimiento SET estado = 'cancelado' WHERE jugador_id = $jugador_id AND pack_id = $pack_id AND estado = 'activo'");

// 2. Insertar nueva malla vinculada al pack
$sql = "INSERT INTO alumno_malla_seguimiento (jugador_id, malla_id, entrenador_id, pack_id, estado, clase_actual_orden, fecha_inicio) 
        VALUES (?, ?, ?, ?, 'activo', 1, NOW())";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iiii", $jugador_id, $malla_id, $entrenador_id, $pack_id);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "id" => $conn->insert_id]);
} else {
    http_response_code(500);
    echo json_encode(["error" => $conn->error]);
}
?>
