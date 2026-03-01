<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit;
}

require_once "../db.php";

// TOKEN
$headers = getallheaders();
$auth = $headers['Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? ($headers['authorization'] ?? ''));

if (!preg_match('/^Bearer\s+(.*)$/', $auth, $matches) || base64_decode($matches[1]) !== "1|padel_academy") {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

/* ========= BODY ========= */
$input = fopen('php://input', 'r');
$data = json_decode(stream_get_contents($input), true);
fclose($input);

try {
    // Verificar conexión con la base de datos
    if (!$conn) {
        throw new Exception("No hay conexión con la base de datos");
    }

    $stmt = $conn->prepare("
        INSERT INTO disponibilidad_profesor
        (profesor_id, fecha_inicio, fecha_fin, club_id, activo)
        VALUES (?, ?, ?, ?, ?)
    ");

    // Verificar si la preparación de la query fue exitosa
    if (!$stmt) {
        throw new Exception("Error al preparar la query");
    }

    foreach ($data as $d) {
        $stmt->bind_param("issss", $d['profesor_id'], $d['fecha_inicio'], $d['fecha_fin'], $d['club_id'], $d['activo']);
        $stmt->execute();

        // Verificar si la ejecución de la query fue exitosa
        if ($stmt->affected_rows == 0) {
            throw new Exception("No se insertaron registros");
        }
    }

    echo json_encode([
        "ok" => true,
        "message" => "Disponibilidad guardada correctamente"
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "error" => $e->getMessage(),
        "file" => $e->getFile(),
        "line" => $e->getLine(),
        "trace" => $e->getTraceAsString()
    ]);
}
?>