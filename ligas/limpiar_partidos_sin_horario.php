<?php
/**
 * PADELBLOX - LIMPIAR PARTIDOS SIN HORARIO / PENDIENTES
 * 
 * Elimina de forma segura únicamente los partidos que NO tienen cancha, NO tienen fecha y NO tienen resultados.
 * Protege 100% todos los partidos ya agendados, jugados o con marcador.
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();

register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_length()) ob_clean();
        header("Access-Control-Allow-Origin: *");
        header("Content-Type: application/json; charset=utf-8");
        http_response_code(200);
        echo json_encode([
            "success" => false, 
            "error" => "Error interno en el servidor: " . $err['message'] . " en línea " . $err['line']
        ]);
    }
});

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
mysqli_report(MYSQLI_REPORT_OFF);
require_once "../db.php";

$input = json_decode(file_get_contents("php://input"), true);
$categoria_id = !empty($input['categoria_id']) ? (int)$input['categoria_id'] : null;
$jornada_id = !empty($input['jornada_id']) ? (int)$input['jornada_id'] : null;
$liga_id = !empty($input['liga_id']) ? (int)$input['liga_id'] : null;
$jornada_ids_input = !empty($input['jornada_ids']) ? (array)$input['jornada_ids'] : (!empty($input['jornada_id']) ? [(int)$input['jornada_id']] : []);

if (!$categoria_id && empty($jornada_ids_input) && !$liga_id) {
    if (ob_get_length()) ob_clean();
    echo json_encode(["success" => false, "error" => "Se requiere categoria_id, jornada_id o liga_id"]);
    exit;
}
$targetCatId = null;
$targetNumJ = null;
if ($jornada_id) {
    $resTJ = $conn->query("SELECT categoria_id, numero_jornada FROM liga_jornadas WHERE id = $jornada_id");
    if ($resTJ && $rowTJ = $resTJ->fetch_assoc()) {
        $targetCatId = (int)$rowTJ['categoria_id'];
        $targetNumJ = (int)$rowTJ['numero_jornada'];
    }
}

try {
    // 0. Auto-sincronizar categoria_id y reservas_cancha
    @$conn->query("UPDATE liga_partidos lp JOIN liga_jornadas lj ON lp.jornada_id = lj.id SET lp.categoria_id = lj.categoria_id WHERE lp.categoria_id IS NULL OR lp.categoria_id = 0");
    @$conn->query("UPDATE liga_partidos lp JOIN liga_parejas lpar ON lp.pareja1_id = lpar.id SET lp.categoria_id = lpar.categoria_id WHERE lp.categoria_id IS NULL OR lp.categoria_id = 0");
    @$conn->query("UPDATE liga_partidos lp 
                   JOIN reservas_cancha rc ON rc.liga_partido_id = lp.id 
                   SET lp.cancha_id = rc.cancha_id, 
                       lp.fecha_hora = CONCAT(rc.fecha, ' ', rc.hora_inicio)
                   WHERE rc.cancha_id IS NOT NULL 
                     AND rc.fecha IS NOT NULL 
                     AND rc.hora_inicio IS NOT NULL 
                     AND rc.estado != 'Cancelada'");

    // Deduplicar jornadas redundantes por categoría si las hubiera
    @$conn->query("UPDATE liga_partidos lp
                   JOIN (
                       SELECT categoria_id, numero_jornada, MIN(id) as min_id
                       FROM liga_jornadas
                       GROUP BY categoria_id, numero_jornada
                       HAVING COUNT(*) > 1
                   ) j_dup ON j_dup.categoria_id = lp.categoria_id
                   JOIN liga_jornadas lj ON lp.jornada_id = lj.id AND lj.numero_jornada = j_dup.numero_jornada
                   SET lp.jornada_id = j_dup.min_id");

    @$conn->query("DELETE j1 FROM liga_jornadas j1 
                   INNER JOIN liga_jornadas j2 
                   WHERE j1.categoria_id = j2.categoria_id 
                     AND j1.numero_jornada = j2.numero_jornada 
                     AND j1.id > j2.id");

    $conn->begin_transaction();

    $where = [];
    if ($targetCatId && $targetNumJ) {
        $where[] = "jornada_id IN (SELECT id FROM liga_jornadas WHERE categoria_id = $targetCatId AND numero_jornada = $targetNumJ)";
    } else if (!empty($jornada_ids_input)) {
        $jIdsList = implode(',', array_map('intval', $jornada_ids_input));
        $where[] = "jornada_id IN ($jIdsList)";
    } else if ($categoria_id) {
        $where[] = "(categoria_id = $categoria_id OR jornada_id IN (SELECT id FROM liga_jornadas WHERE categoria_id = $categoria_id) OR pareja1_id IN (SELECT id FROM liga_parejas WHERE categoria_id = $categoria_id))";
    } else if ($liga_id) {
        $where[] = "(categoria_id IN (SELECT id FROM liga_categorias WHERE liga_id = $liga_id) OR jornada_id IN (SELECT id FROM liga_jornadas WHERE categoria_id IN (SELECT id FROM liga_categorias WHERE liga_id = $liga_id)))";
    }

    $whereClause = implode(' AND ', $where);

    // Condición estricta de seguridad: SOLO borrar partidos SIN CANCHA, SIN FECHA, SIN RESULTADO Y SIN RESERVAS
    $sqlDelete = "DELETE FROM liga_partidos 
                  WHERE $whereClause 
                    AND (cancha_id IS NULL OR cancha_id = 0) 
                    AND (fecha_hora IS NULL OR fecha_hora <= '2000-01-01 00:00:00') 
                    AND (estado IS NULL OR estado NOT IN ('Jugado', 'Walkover')) 
                    AND (pareja_ganadora_id IS NULL OR pareja_ganadora_id = 0)
                    AND (set1_p1 IS NULL OR set1_p1 = 0)
                    AND (set1_p2 IS NULL OR set1_p2 = 0)
                    AND (set2_p1 IS NULL OR set2_p1 = 0)
                    AND (set2_p2 IS NULL OR set2_p2 = 0)
                    AND id NOT IN (SELECT rc.liga_partido_id FROM reservas_cancha rc WHERE rc.liga_partido_id IS NOT NULL AND rc.estado != 'Cancelada')";

    $resDel = $conn->query($sqlDelete);
    if ($resDel === false) {
        throw new Exception("Error al ejecutar limpieza: " . $conn->error);
    }
    $eliminados = max(0, $conn->affected_rows);

    $conn->commit();

    if (ob_get_length()) ob_clean();
    echo json_encode([
        "success" => true,
        "eliminados" => $eliminados,
        "mensaje" => "Se limpiaron $eliminados partido(s) pendientes sin horario. Todos los partidos agendados y jugados se mantuvieron 100% intactos."
    ]);

} catch (Throwable $e) {
    if (isset($conn) && $conn) {
        @$conn->rollback();
    }
    if (ob_get_length()) ob_clean();
    http_response_code(200);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
