<?php
/**
 * PADELBLOX - LIMPIAR PAREJAS Y PARTIDOS DEMO EN LIGAS
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
require_once "../db.php";

$input = json_decode(file_get_contents("php://input"), true);
$liga_id = (int)($input['liga_id'] ?? $_GET['liga_id'] ?? 0);
$categoria_id = (int)($input['categoria_id'] ?? $_GET['categoria_id'] ?? 0);

try {
    $whereCat = "";
    if ($categoria_id > 0) {
        $whereCat = "AND categoria_id = $categoria_id";
    } else if ($liga_id > 0) {
        $whereCat = "AND categoria_id IN (SELECT id FROM liga_categorias WHERE liga_id = $liga_id)";
    }

    // 1. Identificar parejas demo
    $sqlDemos = "SELECT id FROM liga_parejas 
                 WHERE (nombre_pareja LIKE '%Demo%' 
                    OR nombre_externo1 LIKE '%Demo%' 
                    OR nombre_externo2 LIKE '%Demo%'
                    OR (jugador1_id IS NULL AND (nombre_externo1 IS NULL OR TRIM(nombre_externo1) = ''))) $whereCat";
    
    $res = $conn->query($sqlDemos);
    $demoIds = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $demoIds[] = (int)$row['id'];
        }
    }

    $eliminadas = 0;
    if (!empty($demoIds)) {
        $idsStr = implode(',', $demoIds);
        // Borrar partidos asociados a parejas demo
        $conn->query("DELETE FROM liga_partidos WHERE pareja1_id IN ($idsStr) OR pareja2_id IN ($idsStr)");
        // Borrar posiciones asociadas
        $conn->query("DELETE FROM liga_posiciones WHERE pareja_id IN ($idsStr)");
        // Borrar parejas demo
        $conn->query("DELETE FROM liga_parejas WHERE id IN ($idsStr)");
        $eliminadas = count($demoIds);
    }

    echo json_encode([
        "success" => true,
        "mensaje" => "Se eliminaron $eliminadas parejas demo exitosamente.",
        "parejas_eliminadas" => $eliminadas
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
