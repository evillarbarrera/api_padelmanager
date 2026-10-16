<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
mysqli_report(MYSQLI_REPORT_OFF);
require_once "../db.php";
require_once "posiciones_helper.php";

$input = json_decode(file_get_contents("php://input"), true);
$partido_id = !empty($input['partido_id']) ? (int)$input['partido_id'] : null;

if (!$partido_id) {
    echo json_encode(["success" => false, "error" => "ID de partido requerido"]);
    exit;
}

try {
    $conn->begin_transaction();

    // Obtener categoría del partido antes de eliminarlo
    $cat_id = 0;
    $resP = $conn->query("SELECT p.categoria_id, par.categoria_id as par_cat_id 
                          FROM liga_partidos p 
                          LEFT JOIN liga_parejas par ON p.pareja1_id = par.id 
                          WHERE p.id = $partido_id");
    if ($resP && $rowP = $resP->fetch_assoc()) {
        $cat_id = !empty($rowP['categoria_id']) ? (int)$rowP['categoria_id'] : (int)($rowP['par_cat_id'] ?? 0);
    }

    // 1. Eliminar reservas en canchas
    $conn->query("DELETE FROM reservas_cancha WHERE liga_partido_id = $partido_id");

    // 2. Eliminar partido
    $stmt = $conn->prepare("DELETE FROM liga_partidos WHERE id = ?");
    $stmt->bind_param("i", $partido_id);
    $stmt->execute();

    // 3. Recalcular posiciones
    if ($cat_id > 0) {
        recalcularPosicionesCategoria($conn, $cat_id);
    }

    $conn->commit();

    echo json_encode(["success" => true, "mensaje" => "Partido y su reserva eliminados exitosamente."]);
} catch (Throwable $e) {
    if (isset($conn) && $conn) {
        @$conn->rollback();
    }
    http_response_code(200);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
