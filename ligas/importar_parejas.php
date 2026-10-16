<?php
/**
 * PADELBLOX - IMPORTAR/AGREGAR PAREJAS REALES EN LOTE PARA LIGAS
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
require_once "../db.php";
require_once "posiciones_helper.php";

$input = json_decode(file_get_contents("php://input"), true);
$categoria_id = (int)($input['categoria_id'] ?? 0);
$parejasList = $input['parejas'] ?? [];

if ($categoria_id <= 0 || empty($parejasList) || !is_array($parejasList)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Se requiere categoria_id y un arreglo de parejas para registrar."]);
    exit;
}

try {
    $conn->begin_transaction();

    $stmtIns = $conn->prepare("INSERT INTO liga_parejas (categoria_id, jugador1_id, jugador2_id, nombre_externo1, nombre_externo2, nombre_pareja, estado) VALUES (?, ?, ?, ?, ?, ?, 'Confirmada')");

    $insertados = 0;
    foreach ($parejasList as $p) {
        $j1_id = !empty($p['jugador1_id']) ? (int)$p['jugador1_id'] : null;
        $j2_id = !empty($p['jugador2_id']) ? (int)$p['jugador2_id'] : null;
        $n_ext1 = trim($p['nombre_externo1'] ?? $p['j1'] ?? $p['jugador1'] ?? '');
        $n_ext2 = trim($p['nombre_externo2'] ?? $p['j2'] ?? $p['jugador2'] ?? '');
        $n_pareja = trim($p['nombre_pareja'] ?? '');

        if (empty($n_pareja)) {
            $n_pareja = ($n_ext1 ?: 'Jugador 1') . ' / ' . ($n_ext2 ?: 'Jugador 2');
        }

        if ($stmtIns) {
            $stmtIns->bind_param("iiisss", $categoria_id, $j1_id, $j2_id, $n_ext1, $n_ext2, $n_pareja);
            if ($stmtIns->execute()) {
                $insertados++;
            }
        }
    }

    // Inicializar posiciones para la categoría
    recalcularPosicionesCategoria($conn, $categoria_id);

    $conn->commit();
    echo json_encode([
        "success" => true,
        "mensaje" => "Se registraron exitosamente $insertados parejas en la categoría.",
        "insertados" => $insertados
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
