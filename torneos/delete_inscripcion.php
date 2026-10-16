<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=utf-8");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$data = json_decode(file_get_contents("php://input"), true);
$id = $data['inscripcion_id'] ?? 0;

if (!$id) {
    echo json_encode(["error" => "ID de inscripción requerido"]);
    exit;
}

try {
    $conn->begin_transaction();

    // 1. Obtener info de la inscripción para saber la pareja y categoría
    $resIns = $conn->query("SELECT * FROM torneo_inscripciones WHERE id = " . (int)$id);
    if ($resIns && $resIns->num_rows > 0) {
        $ins = $resIns->fetch_assoc();
        $pareja_id = (int)($ins['pareja_id'] ?? 0);
        $cat_id = (int)($ins['categoria_id'] ?? 0);

        if ($pareja_id > 0) {
            // Eliminar partidos asociados a esta pareja en torneo_partidos_v2
            $conn->query("DELETE FROM torneo_partidos_v2 WHERE (pareja1_id = $pareja_id OR pareja2_id = $pareja_id) AND categoria_id = $cat_id");
            
            // Eliminar de torneo_grupo_parejas si existe
            $checkTgp = $conn->query("SHOW TABLES LIKE 'torneo_grupo_parejas'");
            if ($checkTgp && $checkTgp->num_rows > 0) {
                $conn->query("DELETE FROM torneo_grupo_parejas WHERE pareja_id = $pareja_id");
            }
        }
    }

    // 2. Eliminar de torneo_inscripciones
    $sql = "DELETE FROM torneo_inscripciones WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
        throw new Exception("Error al eliminar inscripción: " . $stmt->error);
    }

    $conn->commit();
    echo json_encode(["success" => true, "mensaje" => "Inscripción y partidos asociados eliminados correctamente"]);

} catch (Throwable $e) {
    if (isset($conn) && $conn) {
        @$conn->rollback();
    }
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
?>
