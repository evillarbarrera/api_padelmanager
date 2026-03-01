<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

require_once "../db.php";

// Auth
$headers = getallheaders();
$auth = $headers['Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if ($auth !== 'Bearer ' . base64_encode("1|padel_academy")) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$jugador_id = $_GET['jugador_id'] ?? null;

if (!$jugador_id) {
    http_response_code(400);
    echo json_encode(["error" => "jugador_id es obligatorio"]);
    exit;
}

$sql = "SELECT ev.*, IFNULL(u.nombre, 'Mío') as entrenador_nombre 
        FROM entrenamiento_videos ev
        LEFT JOIN usuarios u ON ev.entrenador_id = u.id
        WHERE ev.jugador_id = ? 
        ORDER BY ev.fecha DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => "Error al preparar consulta: " . $conn->error]);
    exit;
}
$stmt->bind_param("i", $jugador_id);
$stmt->execute();
$result = $stmt->get_result();

$videos = [];
while ($row = $result->fetch_assoc()) {
    $videos[] = $row;
}

echo json_encode($videos);
?>
