<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Authorization
$headers = getallheaders();
$auth = $headers['Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

if ($auth !== 'Bearer ' . base64_encode("1|padel_academy")) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

require_once "../db.php";

$input = json_decode(file_get_contents("php://input"), true);

$crear = $input['crear'] ?? [];
$eliminar = $input['eliminar'] ?? [];

$conn->begin_transaction();

try {

    /* ==========================
       INSERTAR NUEVOS
    =========================== */
    if (!empty($crear)) {
        $insert = $conn->prepare("
            INSERT IGNORE INTO disponibilidad_profesor
            (profesor_id, fecha_inicio, fecha_fin, club_id, activo)
            VALUES (?, ?, ?, ?, 1)
        ");

        $check = $conn->prepare("
            SELECT id FROM disponibilidad_profesor 
            WHERE profesor_id = ? AND fecha_inicio = ? AND fecha_fin = ?
        ");

        foreach ($crear as $c) {
            // Check before insert
            $check->bind_param("iss", $c['profesor_id'], $c['fecha_inicio'], $c['fecha_fin']);
            $check->execute();
            $exists = $check->get_result()->fetch_assoc();

            if (!$exists) {
                $insert->bind_param(
                    "issi",
                    $c['profesor_id'],
                    $c['fecha_inicio'],
                    $c['fecha_fin'],
                    $c['club_id']
                );
                $insert->execute();
            }
        }

        $insert->close();
        $check->close();
    }

    /* ==========================
       ELIMINAR QUITADOS
    =========================== */
    if (!empty($eliminar)) {
        $delete = $conn->prepare("
            DELETE FROM disponibilidad_profesor
            WHERE profesor_id = ?
              AND fecha_inicio = ?
              AND fecha_fin = ?
              AND club_id = ?
        ");

        foreach ($eliminar as $e) {
            $delete->bind_param(
                "issi",
                $e['profesor_id'],
                $e['fecha_inicio'],
                $e['fecha_fin'],
                $e['club_id']
            );
            $delete->execute();
        }

        $delete->close();
    }

    $conn->commit();

    echo json_encode([
        "ok" => true,
        "creados" => count($crear),
        "eliminados" => count($eliminar)
    ]);

} catch (Exception $e) {

    $conn->rollback();
    http_response_code(500);

    echo json_encode([
        "error" => "Error al sincronizar disponibilidad",
        "detalle" => $e->getMessage()
    ]);
}

$conn->close();
