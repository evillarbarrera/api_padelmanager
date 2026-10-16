<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type, Origin, Accept");
header("Content-Type: application/json; charset=UTF-8");

// Manejo de peticiones preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Error de conexión a la base de datos"]);
    exit;
}

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

$torneo_id = 0;
if (!empty($data['torneo_id'])) {
    $torneo_id = (int)$data['torneo_id'];
} elseif (!empty($data['id'])) {
    $torneo_id = (int)$data['id'];
} elseif (!empty($_GET['torneo_id'])) {
    $torneo_id = (int)$_GET['torneo_id'];
} elseif (!empty($_GET['id'])) {
    $torneo_id = (int)$_GET['id'];
}

if (!$torneo_id) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "ID de torneo no proporcionado"]);
    exit;
}

// 1. Obtener datos del torneo para asegurar limpieza de reservas
$stmtT = $conn->prepare("SELECT id, club_id, nombre, fecha, hora_inicio, estado FROM torneos_americanos WHERE id = ?");
$torneo = null;
if ($stmtT) {
    $stmtT->bind_param("i", $torneo_id);
    $stmtT->execute();
    $resT = $stmtT->get_result();
    if ($resT) {
        $torneo = $resT->fetch_assoc();
    }
    $stmtT->close();
}

// 2. Actualizar estado del torneo a 'Cerrado'
$sql = "UPDATE torneos_americanos SET estado = 'Cerrado' WHERE id = ?";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $torneo_id);
    $stmt->execute();
    $stmt->close();
}

// 3. ELIMINAR RESERVAS ASOCIADAS AL AMERICANO
// A. Por torneo_id directo
$conn->query("DELETE FROM reservas_cancha WHERE torneo_id = " . (int)$torneo_id);

// B. Por nombre externo, fecha y club (en caso de reservas creadas sin torneo_id explícito)
if ($torneo && !empty($torneo['club_id']) && !empty($torneo['fecha'])) {
    $cid = (int)$torneo['club_id'];
    $fec = $conn->real_escape_string($torneo['fecha']);
    $nomEsc = !empty($torneo['nombre']) ? $conn->real_escape_string($torneo['nombre']) : '';

    if (!empty($nomEsc)) {
        $conn->query("DELETE r FROM reservas_cancha r 
                      JOIN canchas c ON r.cancha_id = c.id 
                      WHERE c.club_id = $cid 
                        AND r.fecha = '$fec' 
                        AND r.nombre_externo = '$nomEsc'");
    }

    // Limpieza de cualquier reserva de tipo 'americano' que haya quedado huérfana para ese torneo
    $conn->query("DELETE r FROM reservas_cancha r 
                  JOIN canchas c ON r.cancha_id = c.id 
                  WHERE c.club_id = $cid 
                    AND r.fecha = '$fec' 
                    AND (r.tipo_reserva = 'americano' OR r.estado = 'Americano') 
                    AND (r.torneo_id = " . (int)$torneo_id . " OR r.torneo_id IS NULL)");
}

// 4. Limpiar partidos generados huérfanos si existieran
try {
    $checkT = $conn->query("SHOW TABLES LIKE 'torneo_partidos'");
    if ($checkT && $checkT->num_rows > 0) {
        $conn->query("DELETE FROM torneo_partidos WHERE torneo_id = " . (int)$torneo_id);
    }
} catch (Throwable $e) {}

try {
    $checkV2 = $conn->query("SHOW TABLES LIKE 'torneo_partidos_v2'");
    if ($checkV2 && $checkV2->num_rows > 0) {
        $conn->query("DELETE FROM torneo_partidos_v2 WHERE torneo_id = " . (int)$torneo_id);
    }
} catch (Throwable $e) {}

echo json_encode([
    "success" => true,
    "message" => "Torneo cancelado correctamente y reservas eliminadas.",
    "torneo_id" => $torneo_id
]);
?>
