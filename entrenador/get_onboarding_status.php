<?php
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, x-authorization, X-Authorization, Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";

$entrenador_id = $_GET['entrenador_id'] ?? null;

if (!$entrenador_id) {
    ob_end_clean();
    echo json_encode(["success" => false, "error" => "ID faltante"]);
    exit;
}

try {
    $steps = [
        ["id" => 1, "done" => false],
        ["id" => 2, "done" => false],
        ["id" => 3, "done" => false],
        ["id" => 4, "done" => false],
        ["id" => 5, "done" => false]
    ];

    // 1. Packs
    $res1 = $conn->query("SELECT id FROM packs WHERE entrenador_id = $entrenador_id AND activo = 1 LIMIT 1");
    $steps[0]["done"] = ($res1 && $res1->num_rows > 0);

    // 2. Disponibilidad
    $res2 = $conn->query("SELECT id FROM disponibilidad_profesor WHERE profesor_id = $entrenador_id AND activo = 1 LIMIT 1");
    $steps[1]["done"] = ($res2 && $res2->num_rows > 0);

    // 3. Perfil
    $res3 = $conn->query("SELECT foto, foto_perfil, telefono FROM usuarios WHERE id = $entrenador_id");
    if ($res3 && $user = $res3->fetch_assoc()) {
        $photo = !empty($user['foto_perfil']) ? $user['foto_perfil'] : $user['foto'];
        $has_photo = (!empty($photo) && strpos($photo, 'default') === false);
        $has_phone = !empty($user['telefono']);
        $steps[2]["done"] = ($has_photo && $has_phone);
    }

    // 4. Alumnos
    $res4 = $conn->query("SELECT id FROM entrenador_alumno WHERE entrenador_id = $entrenador_id LIMIT 1");
    $has_manual = ($res4 && $res4->num_rows > 0);
    $res4b = $conn->query("SELECT id FROM usuarios WHERE entrenador_creador_id = $entrenador_id AND rol = 'alumno' LIMIT 1");
    $has_created = ($res4b && $res4b->num_rows > 0);
    $steps[3]["done"] = ($has_manual || $has_created);

    // 5. Reservas
    $res5 = $conn->query("SELECT id FROM reservas WHERE entrenador_id = $entrenador_id AND estado != 'cancelado' LIMIT 1");
    $steps[4]["done"] = ($res5 && $res5->num_rows > 0);

    $done_count = 0;
    foreach ($steps as $s) if ($s["done"]) $done_count++;
    $progress = round(($done_count / 5) * 100);

    $response = [
        "success" => true,
        "steps" => $steps,
        "progress" => $progress
    ];

    ob_end_clean();
    echo json_encode($response);

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>
