<?php
ini_set('display_errors', '0');
error_reporting(0);
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
mysqli_report(MYSQLI_REPORT_OFF);
require_once "../db.php";
require_once "posiciones_helper.php";

$input = json_decode(file_get_contents("php://input"), true);
$id = !empty($input['id']) ? (int)$input['id'] : (!empty($input['pareja_id']) ? (int)$input['pareja_id'] : null);

if (!$id) {
    echo json_encode(["success" => false, "error" => "ID de pareja requerido"]);
    exit;
}

try {
    $conn->begin_transaction();

    // 1. Obtener información de la pareja, categoría y liga
    $sql_p = "SELECT p.*, c.liga_id, l.reglas_puntos_json 
              FROM liga_parejas p
              LEFT JOIN liga_categorias c ON p.categoria_id = c.id
              LEFT JOIN ligas l ON c.liga_id = l.id
              WHERE p.id = $id";
    $res_p = $conn->query($sql_p);
    
    if (!$res_p || $res_p->num_rows === 0) {
        $conn->rollback();
        echo json_encode(["success" => false, "error" => "Pareja no encontrada"]);
        exit;
    }

    $pareja = $res_p->fetch_assoc();
    $cat_id = (int)($pareja['categoria_id'] ?? 0);
    $reglas = !empty($pareja['reglas_puntos_json']) ? json_decode($pareja['reglas_puntos_json'], true) : [];

    // 2. Buscar todos los partidos en el fixture asociados a esta pareja
    $sql_matches = "SELECT id, grupo_id, jornada_id, categoria_id 
                    FROM liga_partidos 
                    WHERE pareja1_id = $id OR pareja2_id = $id";
    $res_m = $conn->query($sql_matches);
    
    $partidos_ids = [];
    $grupos_afectados = [];
    if ($res_m) {
        while ($m = $res_m->fetch_assoc()) {
            $partidos_ids[] = (int)$m['id'];
            if (!empty($m['grupo_id'])) {
                $grupos_afectados[(int)$m['grupo_id']] = true;
            }
        }
    }

    $total_partidos_eliminados = count($partidos_ids);

    // 3. Si existen partidos asociados:
    if (!empty($partidos_ids)) {
        $ids_str = implode(',', $partidos_ids);

        // a. Eliminar reservas de cancha vinculadas a estos partidos
        $conn->query("DELETE FROM reservas_cancha WHERE liga_partido_id IN ($ids_str)");

        // b. Eliminar los partidos del fixture
        $conn->query("DELETE FROM liga_partidos WHERE id IN ($ids_str)");
    }

    // 4. Eliminar a la pareja de la tabla de posiciones de liga
    $conn->query("DELETE FROM liga_posiciones WHERE pareja_id = $id");

    // 5. Eliminar jornadas que hayan quedado vacías (sin partidos) en esta categoría
    if ($cat_id > 0) {
        $conn->query("DELETE FROM liga_jornadas 
                      WHERE categoria_id = $cat_id 
                        AND id NOT IN (SELECT DISTINCT jornada_id FROM liga_partidos WHERE categoria_id = $cat_id AND jornada_id IS NOT NULL)");
    }

    // 6. Finalmente, eliminar la pareja de la tabla liga_parejas
    $stmtDel = $conn->prepare("DELETE FROM liga_parejas WHERE id = ?");
    $stmtDel->bind_param("i", $id);
    if (!$stmtDel->execute()) {
        throw new Exception("Error al eliminar la pareja: " . $stmtDel->error);
    }

    // 7. Recalcular las posiciones para la categoría completa
    if ($cat_id > 0) {
        recalcularPosicionesCategoria($conn, $cat_id);
    }

    $conn->commit();

    echo json_encode([
        "success" => true,
        "mensaje" => "Pareja dada de baja exitosamente" . ($total_partidos_eliminados > 0 ? " y $total_partidos_eliminados partido(s) del fixture asociados fueron eliminados." : "."),
        "partidos_eliminados" => $total_partidos_eliminados
    ]);

} catch (Throwable $e) {
    if (isset($conn) && $conn) {
        @$conn->rollback();
    }
    http_response_code(200);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
