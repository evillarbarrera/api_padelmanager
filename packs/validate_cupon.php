<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

require_once "../db.php";

$codigo = strtoupper(trim($_GET['codigo'] ?? ''));
$entrenador_id = $_GET['entrenador_id'] ?? null;
$jugador_id = $_GET['jugador_id'] ?? null;
$pack_id = $_GET['pack_id'] ?? null;

if (!$codigo || !$entrenador_id) {
    echo json_encode(["error" => "Código y entrenador_id requeridos"]);
    exit;
}

$sql = "SELECT * FROM cupones 
        WHERE codigo = ? 
        AND entrenador_id = ? 
        AND activo = 1 
        AND (fecha_inicio IS NULL OR fecha_inicio <= CURDATE())
        AND (fecha_fin IS NULL OR fecha_fin >= CURDATE())
        AND (uso_maximo IS NULL OR uso_actual < uso_maximo)";

$stmt = $conn->prepare($sql);
$stmt->bind_param("si", $codigo, $entrenador_id);
$stmt->execute();
$result = $stmt->get_result();
$cupon = $result->fetch_assoc();

if (!$cupon) {
    echo json_encode(["error" => "Cupón no válido o expirado"]);
    exit;
}

// Restricción por jugador
if ($cupon['jugador_id'] && $cupon['jugador_id'] != $jugador_id) {
    echo json_encode(["error" => "Este cupón no es válido para ti"]);
    exit;
}

// Restricción por pack
if ($cupon['pack_id'] && $cupon['pack_id'] != $pack_id) {
    echo json_encode(["error" => "Este cupón no es válido para este pack"]);
    exit;
}

echo json_encode(["success" => true, "cupon" => $cupon]);
$conn->close();
?>
