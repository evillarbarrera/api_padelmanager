<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// ⚠️ Mantenemos la conexión simple en la raíz
include_once 'db.php';

if (!isset($conn)) {
    die(json_encode(['success' => false, 'message' => 'Error: Variable $conn no definida']));
}

$input = file_get_contents(\"php://input\");
$data = json_decode($input, true);

if (!$data) {
    die(json_encode(['success' => false, 'message' => 'Error: JSON inválido', 'raw' => $input]));
}

$id_entrenador = (int)$data['id_entrenador'];
$nombre = mysqli_real_escape_string($conn, $data['nombre']);
$notas = isset($data['notas']) ? mysqli_real_escape_string($conn, $data['notas']) : '';
$contenido_json = mysqli_real_escape_string($conn, $data['contenido_json']);
$id = isset($data['id']) ? (int)$data['id'] : 0;

if ($id > 0) {
    $sql = \"UPDATE pizarras_tacticas SET nombre = '$nombre', contenido_json = '$contenido_json', notas = '$notas' WHERE id = $id AND id_entrenador = $id_entrenador\";
} else {
    $sql = \"INSERT INTO pizarras_tacticas (id_entrenador, nombre, contenido_json, notas) VALUES ($id_entrenador, '$nombre', '$contenido_json', '$notas')\";
}

if (mysqli_query($conn, $sql)) {
    echo json_encode(['success' => true, 'id' => ($id > 0 ? $id : mysqli_insert_id($conn))]);
} else {
    echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
}
?>
