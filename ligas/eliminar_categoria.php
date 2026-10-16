<?php
ini_set('display_errors', '0');
error_reporting(0);
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
mysqli_report(MYSQLI_REPORT_OFF);
require_once "../db.php";

$input = json_decode(file_get_contents("php://input"), true);
$cat_id = !empty($input['categoria_id']) ? (int)$input['categoria_id'] : (!empty($input['id']) ? (int)$input['id'] : null);

if (!$cat_id) {
    echo json_encode(["success" => false, "error" => "ID de categoría requerido"]);
    exit;
}

try {
    $conn->begin_transaction();

    // 1. Eliminar partidos de la categoría
    $conn->query("DELETE FROM liga_partidos WHERE grupo_id IN (SELECT id FROM liga_grupos WHERE categoria_id = $cat_id) OR jornada_id IN (SELECT id FROM liga_jornadas WHERE categoria_id = $cat_id) OR pareja1_id IN (SELECT id FROM liga_parejas WHERE categoria_id = $cat_id) OR pareja2_id IN (SELECT id FROM liga_parejas WHERE categoria_id = $cat_id)");

    // 2. Eliminar tabla de posiciones
    $conn->query("DELETE FROM liga_posiciones WHERE grupo_id IN (SELECT id FROM liga_grupos WHERE categoria_id = $cat_id) OR pareja_id IN (SELECT id FROM liga_parejas WHERE categoria_id = $cat_id)");

    // 3. Eliminar parejas inscritas
    $conn->query("DELETE FROM liga_parejas WHERE categoria_id = $cat_id");

    // 4. Eliminar grupos
    $conn->query("DELETE FROM liga_grupos WHERE categoria_id = $cat_id");

    // 5. Eliminar jornadas
    $conn->query("DELETE FROM liga_jornadas WHERE categoria_id = $cat_id");

    // 6. Eliminar la categoría
    $stmt = $conn->prepare("DELETE FROM liga_categorias WHERE id = ?");
    $stmt->bind_param("i", $cat_id);
    $stmt->execute();

    $conn->commit();
    echo json_encode(["success" => true, "mensaje" => "Categoría y sus inscritos/partidos asociados fueron eliminados correctamente"]);
} catch (Throwable $e) {
    $conn->rollback();
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
