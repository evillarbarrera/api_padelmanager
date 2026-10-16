<?php
/**
 * PADELBLOX - BORRAR FIXTURE DE LIGA Y SUS RESERVAS (SIN NOTIFICACIONES)
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

try {
    require_once "../db.php";

    $input = json_decode(file_get_contents("php://input"), true);
    $liga_id = (int)($input['liga_id'] ?? $_GET['liga_id'] ?? 0);
    $categoria_id = (int)($input['categoria_id'] ?? $_GET['categoria_id'] ?? 0);

    if ($categoria_id <= 0 && $liga_id <= 0) {
        @ob_clean();
        echo json_encode(["success" => false, "error" => "ID de liga o categoría es requerido."]);
        exit;
    }

    $wherePartidos = "";
    $whereJornadas = "";
    $whereCategorias = "";

    if ($categoria_id > 0) {
        $wherePartidos = "categoria_id = $categoria_id";
        $whereJornadas = "categoria_id = $categoria_id";
        $whereCategorias = "categoria_id = $categoria_id";
    } else if ($liga_id > 0) {
        $wherePartidos = "categoria_id IN (SELECT id FROM liga_categorias WHERE liga_id = $liga_id)";
        $whereJornadas = "categoria_id IN (SELECT id FROM liga_categorias WHERE liga_id = $liga_id)";
        $whereCategorias = "categoria_id IN (SELECT id FROM liga_categorias WHERE liga_id = $liga_id)";
    }

    $conn->begin_transaction();

    // 1. Borrar reservas de cancha asociadas a partidos de esta liga
    $sqlRes = "DELETE FROM reservas_cancha WHERE liga_partido_id IN (SELECT id FROM liga_partidos WHERE $wherePartidos)";
    $conn->query($sqlRes);

    // 2. Borrar partidos de la liga
    $sqlPartidos = "DELETE FROM liga_partidos WHERE $wherePartidos";
    $conn->query($sqlPartidos);

    // 3. Borrar jornadas
    $sqlJornadas = "DELETE FROM liga_jornadas WHERE $whereJornadas";
    $conn->query($sqlJornadas);

    // 4. Borrar posiciones de grupos
    $sqlPosiciones = "DELETE FROM liga_posiciones WHERE grupo_id IN (SELECT id FROM liga_grupos WHERE $whereCategorias)";
    $conn->query($sqlPosiciones);

    // 5. Borrar grupos
    $sqlGrupos = "DELETE FROM liga_grupos WHERE $whereCategorias";
    $conn->query($sqlGrupos);

    // 6. Resetear grupo_id en parejas de la liga
    $sqlResetParejas = "UPDATE liga_parejas SET grupo_id = NULL WHERE $whereCategorias";
    $conn->query($sqlResetParejas);

    // 7. Borrar parejas demo generadas automáticamente
    $sqlDemo = "DELETE FROM liga_parejas WHERE (nombre_pareja LIKE '%Demo%' OR nombre_externo1 LIKE '%Demo%' OR nombre_externo2 LIKE '%Demo%') AND $whereCategorias";
    $conn->query($sqlDemo);

    $conn->commit();

    @ob_clean();
    echo json_encode([
        "success" => true,
        "mensaje" => "Se ha eliminado el fixture, las jornadas, los grupos y todas las reservas en canchas asociadas de forma silenciosa."
    ]);

} catch (Throwable $e) {
    if (isset($conn) && $conn) {
        @$conn->rollback();
    }
    @ob_clean();
    http_response_code(200);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
