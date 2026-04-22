<?php
// p_save.php - Mirroring login.php structure
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, X-Authorization, Content-Type");
    http_response_code(200);
    exit;
}

require_once "db.php";

$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    die(json_encode(["success" => false, "error" => "JSON vacío"]));
}

$id_entrenador = (int)$data['id_entrenador'];
$nombre = $data['nombre'] ?? 'Sin nombre';
$notas = $data['notas'] ?? '';
$contenido_json = $data['contenido_json'] ?? '';
$id = isset($data['id']) ? (int)$data['id'] : 0;

if ($id > 0) {
    $sql = "UPDATE pizarras_tacticas SET nombre = ?, contenido_json = ?, notas = ? WHERE id = ? AND id_entrenador = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssii", $nombre, $contenido_json, $notas, $id, $id_entrenador);
} else {
    $sql = "INSERT INTO pizarras_tacticas (id_entrenador, nombre, contenido_json, notas) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isss", $id_entrenador, $nombre, $contenido_json, $notas);
}

if ($stmt->execute()) {
    $newId = $id > 0 ? $id : $conn->insert_id;
    echo json_encode([
        "success" => true,
        "id" => $newId
    ]);
} else {
    echo json_encode([
        "success" => false, 
        "error" => "Error de ejecución: " . $stmt->error
    ]);
}
?>
